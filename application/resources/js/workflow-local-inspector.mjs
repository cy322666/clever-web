import {clone, setPath, flatActions, remapReferences} from './workflow-browser-document.mjs';

const getPath=(object,path,fallback)=>{const keys=String(path).split('.');if(keys.some(key=>['__proto__','constructor','prototype'].includes(key))) return fallback;const value=keys.reduce((value,key)=>value?.[key],object);return value===undefined?fallback:value;};
const nestedNodes=actions=>(actions||[]).flatMap(node=>[node,...nestedNodes(node.config?.true_actions),...nestedNodes(node.config?.false_actions)]);

const equal = (a, b) => JSON.stringify(a) === JSON.stringify(b);
const at = (path, indexes) => { let n = 0; return path.replaceAll('*', () => indexes[n++]); };
const sameChoice = (a, b) => a == null || b == null ? a == null && b == null
    : typeof a === 'object' || typeof b === 'object' ? equal(a, b) : String(a) === String(b);

export function inspectorRule(rule, config, indexes = [], conditionOptions = () => null) {
    if (!rule || typeof rule !== 'object' || Array.isArray(rule)) return rule;
    if (rule.copyValue) return getPath(config,at(rule.copyValue,indexes));
    if (rule.conditionValues) { const options=conditionOptions(); return typeof rule.fallback==='boolean' ? options!==null : options || []; }
    if (!rule.dependency || !Array.isArray(rule.cases)) return rule;
    const value = getPath(config, at(rule.dependency, indexes));
    const branch = rule.cases.find(item => sameChoice(item.value, value));
    return inspectorRule(branch ? branch.result : rule.fallback, config, indexes, conditionOptions);
}

export class WorkflowLocalInspector {
    constructor(wire, boot) {
        this.wire = wire;
        this.boot = boot;
        this.id = null;
        this.config = {};
        this.base = {};
        this.schema = [];
        this.revision = 0;
        this.controls = new Map();
        this.sections = new Map();
        this.errors = {};
        this.updateQueued = false;
    }
    node(id = this.id, actions = this.wire.workflowActions) {
        if (id === 'trigger') return this.wire.trigger;
        if (id?.startsWith('trigger:')) return this.wire.definition.additional_triggers?.find(node => node.id === id);
        return nestedNodes(actions).find(node => String(node.id) === String(id?.replace(/^action:/, '')));
    }
    open(id) {
        this.flush();
        const node = this.node(id);
        if (!node) throw new Error('Нода не найдена.');
        this.id = id;
        if (this.panel) this.panel.error=Object.values(this.errors[id] || {}).flat().join(' ');
        this.sourceCache = null;
        this.type = node.type;
        this.schema = this.boot.schemas[(id.startsWith('trigger') ? 'trigger:' : 'action:') + node.type];
        if (!this.schema) throw new Error('Схема настроек не загружена. Обновите страницу.');
        this.base = clone(node.config || {});
        this.config = clone(node.config || {});
        if (Array.isArray(this.config)) this.config = {};
        if (node.type === 'schedule' && !this.config.rules) this.config = {...this.config,timezone:this.config.timezone || this.boot.timezone,rules:[Object.keys(this.config).length?clone(this.config):{frequency:'daily',time:'09:00'}]};
        this.defaults(this.schema);
        const declared=new Set();
        const collect=fields=>fields.forEach(field=>{declared.add(field.path);collect(field.children || []);});collect(this.schema);
        if (node.type.startsWith('amocrm_') && declared.has('entity_source')) {
            const configured = this.config.target_entity_id ?? this.config.entity_id;
            this.config.entity_source ||= configured !== null && configured !== undefined && configured !== '' ? 'manual' : 'context';
            const fields=new Map(this.sources()[0]?.fields.map(field=>[field.key,field.value]) || []);
            const singular=value=>({leads:'lead',contacts:'contact',companies:'company',customers:'customer'}[value] || value);
            const fixed={amocrm_copy_lead:'lead',amocrm_get_contact:'contact',amocrm_update_lead_fields:'lead',amocrm_update_contact_fields:'contact',amocrm_update_company_fields:'company',amocrm_change_lead_status:'lead',amocrm_distribution_queue:'lead'}[node.type];
            let context=singular(fields.get('.entity'));
            if (!['lead','contact','company','customer','task'].includes(context)) context=['lead','contact','company','customer','task'].find(entity=>fields.get('.'+entity+'.id') != null);
            const entity=fixed || context || singular(this.config.target_entity || 'lead');
            this.config.__context_entity=entity;
            this.config.__context_entity_id=['.'+entity+'.id','.item.id','.id'].map(key=>fields.get(key)).find(value=>Number(value)>0 && Number.isInteger(Number(value))) ?? null;
            if (context && context!==entity) this.config.__context_entity_id=null;
            if (this.config.entity_source==='context' && context) this.config.target_entity=String(this.config.target_entity || '').endsWith('s') ? ({lead:'leads',contact:'contacts',company:'companies',customer:'customers'}[entity] || entity) : entity;
            if (['amocrm_update_lead_fields','amocrm_update_contact_fields','amocrm_update_company_fields'].includes(node.type)) {
                this.config.fields=(this.config.fields || []).filter(field=>{
                    if (!field.field?.startsWith('system:') || field.value==null || field.value==='') return true;
                    this.config.standard_fields ||= {}; this.config.standard_fields[field.field.slice(7)] ??= field.value;
                    return false;
                });
            }
        }
        if (node.type === 'amocrm_read') {
            if(this.config.body_mode==='json') {
                try {
                    const query=JSON.parse(this.config.json_body || '{}'), parameters=[];
                    const flatten=(items,prefix='')=>{
                        if(!items || typeof items!=='object') return false;
                        for(const [key,value] of Object.entries(items)) {
                            if(!/^[a-zA-Z0-9_]+$/.test(key)) return false;
                            const name=prefix?prefix+'['+key+']':key;
                            if(value!==null && typeof value==='object') {if(!flatten(value,name)) return false;}
                            else if(value!==null) parameters.push({name,value:typeof value==='boolean'?Number(value):value});
                        }
                        return true;
                    };
                    if(!Array.isArray(query) && flatten(query)) {this.config.body_mode='fields';this.config.parameters=parameters;delete this.config.json_body;}
                } catch { /* Keep dynamic or invalid legacy JSON editable. */ }
            }
            this.config.operation_variant = this.config.operation;
        }
        this.controls.clear();
        this.revision++;
        return this;
    }
    defaults(fields, indexes = []) {
        for (const field of fields) {
            const path = at(field.path, indexes);
            if (!['group','section','view'].includes(field.kind) && getPath(this.config, path) === undefined) {
                const value = inspectorRule(field.default, this.config, indexes);
                if (value !== undefined && value !== null) setPath(this.config, path, clone(value));
            }
            if (field.kind === 'repeater') {
                let rows = getPath(this.config, path);
                if (!Array.isArray(rows)) { rows = Object.values(rows || {}); setPath(this.config, path, rows); }
                rows.forEach((_, index) => this.defaults(field.children, [...indexes,index]));
            } else this.defaults(field.children || [], indexes);
        }
    }
    rule(value, indexes = []) {
        return inspectorRule(value, this.config, indexes, () => {
            const expression=this.config.conditions?.[indexes[0]]?.left;
            const match=typeof expression==='string' && expression.match(/^\s*\{\{\s*(lead|contact|company|customer)\.cf\(\s*(\d+)\s*\)\s*\}\}\s*$/);
            const entity=match && {lead:'leads',contact:'contacts',company:'companies',customer:'customers'}[match[1]];
            const field=match && this.boot.conditionFields.find(field=>String(field.id)===match[2] && field.entity===entity);
            if (!field || !['select','multiselect','radiobutton'].includes(field.type)) return null;
            let enums=field.enums;
            if(typeof enums==='string') {try{enums=JSON.parse(enums);}catch{enums=[];}}
            return Object.fromEntries(Object.values(enums||[]).map(item=>[item.value ?? item.name,item.value ?? item.name]));
        });
    }
    value(path) { return getPath(this.config, path); }
    set(path, value) {
        if (value === undefined) value = null;
        if (equal(this.value(path), value)) return;
        const previous = this.value(path);
        if (this.errors[this.id]) delete this.errors[this.id][path];
        if (this.panel) this.panel.error=Object.values(this.errors[this.id] || {}).flat().join(' ');
        setPath(this.config, path, clone(value));
        this.dependencies(path, value, previous);
        this.revision++;
        // One microtask per input event, with no HTTP or canvas reconstruction.
        if (!this.updateQueued) {
            this.updateQueued = true;
            queueMicrotask(() => { this.updateQueued = false; this.flush(); this.refresh?.(); });
        }
    }
    dependencies(path, value) {
        const sibling = key => path.slice(0, path.lastIndexOf('.') + 1) + key;
        if (path === 'operation_variant') {
            this.config.operation = value;
            this.config.body_mode = this.boot.queryOperations.includes(value) ? 'builder' : 'fields';
        }
        if (path === 'body_mode' && value === 'builder') {
            for (const [key, item] of Object.entries({limit:50,page:1,direction:'asc'})) if (this.config[key] == null || this.config[key] === '') this.config[key] = item;
        }
        if (path === 'with_preset' && value) {
            const allowed = this.boot.withOptions[this.config.operation] || {};
            if (Object.hasOwn(allowed, value)) {
                const rows = this.config.parameters ||= [];
                const found = rows.find(row => row.name === 'with');
                if (found) found.value = [...new Set([...String(found.value || '').split(',').map(v => v.trim()).filter(Boolean), value])].join(',');
                else rows.push({name:'with',value});
            }
            this.config.with_preset = null;
            this.rebuild?.();
        }
        if (path === 'target_entity') {
            this.config.target_entity_id = null;
            if (this.type === 'amocrm_find_entity') {
                this.config.conditions = [];
                this.config.context_key = 'found_' + value + '_' + (this.config.context_key?.match(/^found_(?:lead|contact|company|customer)_(\d+)$/)?.[1] || '1');
                this.rebuild?.();
            }
        }
        if (path === 'pipeline_id' || path === 'standard_fields.pipeline_id') setPath(this.config, sibling('status_id'), null);
        if (/^filters\.\d+\.field$/.test(path)) for (const [key, item] of Object.entries({operator:'eq',value:null,pipeline_id:null})) setPath(this.config, sibling(key), item);
        if (/^filters\.\d+\.pipeline_id$/.test(path)) setPath(this.config, sibling('value'), null);
    }
    cleanConfig() {
        const config = clone(this.config);
        const clean = (fields, indexes = []) => {
            for (const field of fields) {
                const path = at(field.path, indexes);
                if (field.name?.startsWith('__') || ['operation_variant','with_preset'].includes(field.name)) {
                    const parts = path.split('.'); const key = parts.pop(); const parent = parts.length ? getPath(config, parts.join('.')) : config;
                    if (parent) delete parent[key];
                }
                if (field.kind === 'repeater') (getPath(config,path) || []).forEach((_,i) => clean(field.children,[...indexes,i]));
                else clean(field.children || [],indexes);
            }
        };
        clean(this.schema);
        for(const key of ['__context_entity','__context_entity_id','__filter_item_field']) delete config[key];
        return config;
    }
    flush() {
        if (!this.id) return;
        // Merely opening a form must not add defaults or alter a secret/config.
        if (this.flushedRevision === this.revision || equal(this.config, this.openConfig)) return;
        if (!this.openConfig) { this.openConfig = clone(this.config); this.flushedRevision = this.revision; return; }
        const config = this.cleanConfig();
        const actions = clone(this.wire.workflowActions);
        const definition = clone(this.wire.definition);
        if (this.id.startsWith('action:')) {
            const node = this.node(this.id, actions);
            if (!node) return;
            node.config = config;
            definition.actions = actions;
            this.wire.$set('workflowActions', actions, false);
        } else if (this.id === 'trigger') {
            const trigger = {...this.wire.trigger, config};
            definition.trigger = trigger;
            this.wire.$set('trigger', trigger, false);
        } else {
            const start = definition.additional_triggers?.find(node => node.id === this.id);
            if (!start) return;
            start.config = config;
        }
        this.wire.$set('definition', definition, false);
        this.wire.$set('workflowLocalInspectorDirty', {...this.wire.workflowLocalInspectorDirty,[this.id]:true}, false);
        this.flushedRevision = this.revision;
    }
    begin(id) {
        this.open(id);
        this.sourceCache = null;
        this.openConfig = clone(this.config);
        this.flushedRevision = this.revision;
        this.names = this.referenceNames();
    }
    cancel() {
        if (!this.id) return;
        this.config = clone(this.base);
        this.revision++;
        this.flush();
    }
    add(field, indexes) {
        const path = at(field.path,indexes);
        const rows = [...(this.value(path) || [])];
        if (field.maxItems !== null && rows.length >= field.maxItems) return;
        rows.push({}); setPath(this.config,path,rows);
        this.defaults(field.children,[...indexes,rows.length-1]);
        this.revision++; this.flush(); this.rebuild?.();
    }
    remove(field,indexes,index) {
        const path = at(field.path,indexes), rows = [...this.value(path)];
        if (rows.length <= (field.minItems || 0)) return;
        rows.splice(index,1); setPath(this.config,path,rows);
        this.revision++; this.flush(); this.rebuild?.();
    }
    reorder(field,indexes,from,to) {
        const path=at(field.path,indexes), rows=[...this.value(path)];
        if (!field.reorderable || from<0 || to<0 || from>=rows.length || to>=rows.length || from===to) return;
        rows.splice(to,0,rows.splice(from,1)[0]);setPath(this.config,path,rows);
        this.revision++;this.flush();this.rebuild?.();
    }
    sources() {
        if (this.sourceCache) return this.sourceCache;
        const actions=flatActions(this.wire.workflowActions), byId=new Map(actions.map(node=>['action:'+node.id,node]));
        const edges=this.wire.definition.connections || [];
        const ancestors=new Set(), pending=[this.id];
        while(pending.length) {
            const id=pending.pop();
            for(const edge of edges.filter(edge=>edge.targetId===id)) if (!ancestors.has(edge.sourceId) && edge.sourceId!==this.id) {ancestors.add(edge.sourceId);pending.push(edge.sourceId);}
        }
        if (!edges.length) for (const node of actions) {if('action:'+node.id===this.id) break;ancestors.add('action:'+node.id);}
        const recorded=this.wire.debugState?.results || [];
        const index=this.wire.debugSelectedResultIndex;
        const results=new Map((Number.isInteger(index) ? recorded.slice(0,index) : recorded).map(result=>[result.id,result]));
        if (!Number.isInteger(index)) for (const result of Object.values(this.wire.nodeRunResults || {})) if(result?.id) results.set(result.id,result);
        const template=new Map(this.boot.sources.map(source=>[source.id,source]));
        const trigger=this.wire.debugState?.start_node_id || this.wire.debugStartNodeId || 'trigger';
        let input=this.wire.debugState?.display_trigger_data;
        if (input===undefined) {try{input=JSON.parse(this.wire.debugInput || '{}');}catch{input={};}}
        const start=this.node(trigger);
        const rootTemplate=template.get(trigger);
        const sourceName=start?.name || rootTemplate?.name || 'Данные запуска';
        const sources=[this.wire.debugState?.display_trigger_data===undefined && Object.keys(input || {}).length===0 && rootTemplate
            ? namedSource(rootTemplate,sourceName) : inspectorSource(trigger,sourceName,input,true)];
        for (const [id,node] of byId) if (ancestors.has(id)) {
            const result=results.get(node.id), fallback=template.get(node.id);
            if (result) sources.push(inspectorSource(node.id,node.name || fallback?.name || node.type,
                Object.hasOwn(result,'display_output') ? result.display_output : result.output,result.output_recorded!==false));
            else if (fallback) {const source=namedSource(fallback,node.name || fallback.name);sources.push({...source,available:false,fields:source.fields.map(field=>({...field,available:false}))});}
            else sources.push(inspectorSource(node.id,node.name || node.type,{},false));
        }
        const variables={},known=new Set();
        const varFields=new Map((template.get('$vars')?.fields || []).map(field=>[field.key,field]));
        for(const node of actions.filter(node=>node.type==='workflow_set_variable' && ancestors.has('action:'+node.id))) {
            const result=results.get(node.id), output=result?.display_output ?? result?.output;
            for(const row of node.config.assignments || []) if(/^[A-Za-z][A-Za-z0-9_]{0,63}$/.test(row.name || '')) {
                const field=varFields.get('.'+row.name);
                variables[row.name]=output && Object.hasOwn(output,row.name) ? output[row.name] : !Number.isInteger(index) && field?.available ? field.value : null;
                if(output && Object.hasOwn(output,row.name) || !Number.isInteger(index) && field?.available) known.add(row.name);
            }
        }
        if(Object.keys(variables).length) {
            const source=inspectorSource('$vars','Переменные текущего прохода',variables,true);
            source.fields=source.fields.map(field=>({...field,expression:field.expression?'{{ $vars'+field.key+' }}':null,available:field.key==='' || known.has(field.key.match(/^\.([A-Za-z][A-Za-z0-9_]*)/)?.[1])}));
            sources.push(source);
        }
        return this.sourceCache=sources;
    }
    output() {
        const id=this.id?.replace(/^action:/,''), preview=this.wire.nodeRunResults?.[id];
        const result=preview || (this.wire.debugState?.results || []).findLast(result=>result.id===id);
        return result ? Object.hasOwn(result,'display_output') ? result.display_output : result.output
            : {message:'Запустите ноду, чтобы увидеть результат.'};
    }
    synchronize() {
        if (!this.id) return;
        const node=this.node();
        if (!node) {this.panel?.hideInspector();this.id=null;return;}
        if(node.type!==this.type) {this.begin(this.id);this.render?.();this.renderInput?.();return;}
        const currentNames=this.referenceNames();
        const names=Object.fromEntries(Object.entries(currentNames).filter(([id,name])=>this.names?.[id] && this.names[id]!==name).map(([id,name])=>[this.names[id],name]));
        if (Object.keys(names).length) {
            this.base=remapReferences(this.base,names);
            this.config=remapReferences(this.config,names);
            this.openConfig=remapReferences(this.openConfig,names);
        }
        this.names=currentNames;
        this.sourceCache=null;
        if (this.panel) this.panel.title=node.name || this.panel.title;
        this.refresh?.();this.renderInput?.();
    }
    referenceNames() {
        return Object.fromEntries([...(this.wire.trigger?[['trigger',this.wire.trigger.name]]:[]),...(this.wire.definition.additional_triggers || []).map(node=>[node.id,node.name]),...nestedNodes(this.wire.workflowActions).map(node=>[node.id,node.name])]);
    }
}

function namedSource(source,name) {
    return {...source,name,fields:source.fields.map(field=>({...field,expression:field.expression?remapReferences(field.expression,{[source.name]:name}):null}))};
}

export function inspectorSource(id,name,value,available) {
    const fields=[];
    const visit=(value,key='',parent=null,depth=0,label='Результат',addressable=true)=>{
        if(fields.length>=150 || depth>6) return;
        const array=Array.isArray(value), object=value!==null && typeof value==='object';
        const type=object ? array?'array':'object' : value===null?'null':typeof value==='number' ? Number.isInteger(value)?'int':'float' : typeof value==='boolean'?'bool':typeof value;
        fields.push({path:key || 'Весь результат',key,parent,depth,label,type,available,count:object?Object.keys(value).length:null,
            value:object?null:value,expression:addressable?'{{ $('+JSON.stringify(name)+')'+key+' }}':null,
            ...(array?{item_fields:itemFields(value)}:{}),});
        if(object && !array) for(const child in value) {
            if ((depth===0 && child==='headers') || /token|password|authorization|secret/i.test(child)) continue;
            const item=value[child];
            const path=array?key+'['+child+']':key+(/^[\p{L}_][\p{L}\p{N}_]*$/u.test(child)?'.'+child:'['+JSON.stringify(child)+']');
            visit(item,path,key,depth+1,child,addressable && !array);
        }
    };
    visit(value);
    return {id,name,fields,available,truncated:fields.length>=150};
}

function itemFields(items) {
    const fields=new Set();
    const visit=(item,prefix='',depth=0)=>{
        if (!item || Array.isArray(item) || typeof item!=='object' || depth>4 || fields.size>=100) return;
        for(const key in item) {
            if(!/^[\p{L}\p{N}_-]+$/u.test(key) || /token|password|authorization|secret/i.test(key)) continue;
            const path=prefix?prefix+'.'+key:key;fields.add(path);visit(item[key],path,depth+1);
            if(fields.size>=100) break;
        }
    };
    items.slice(0,20).forEach(item=>visit(item));return [...fields];
}

const el = (tag, className = '', text = '') => { const node = document.createElement(tag); node.className = className; node.textContent = text; return node; };
const json = value => JSON.stringify(value ?? null);

/** Keep the production field widgets and CSS. Only their state owner changes. */
export function installLocalInspector(panel, boot, root) {
    const local = new WorkflowLocalInspector(panel.$wire, boot);
    local.panel = panel;
    local.root = root;
    local.widgetsReady = Promise.all([
        import(/* @vite-ignore */ boot.selectUrl).then(module => local.selectFactory=module.default),
        import(/* @vite-ignore */ boot.codeEditorUrl).then(module => local.codeFactory=module.default),
    ]);
    local.template = name => root.querySelector('[data-local-inspector-template="'+name+'"]')?.content.firstElementChild.cloneNode(true);
    local.render = () => {
        const target = root.querySelector('[data-local-inspector-fields]');
        window.Alpine.mutateDom(() => {
            for (const node of [...target.children]) window.Alpine.destroyTree(node);
            target.replaceChildren(); local.controls.clear();
            const schema=el('div','fi-sc fi-grid fi-sc-has-gap');grid(schema,{default:1});
            target.append(schema);append(local.schema,schema,[]);window.Alpine.initTree(schema);
        });
        local.refresh();
    };
    local.rebuild = () => queueMicrotask(() => local.render());
    local.renderInput = () => {
        const target=root.querySelector('[data-local-inspector-input]');
        window.Alpine.mutateDom(()=>{
            for(const node of [...target.children]) window.Alpine.destroyTree(node);
            const picker=local.template('input');target.replaceChildren(picker);window.Alpine.initTree(picker);
        });
    };
    local.refresh = () => {
        for (const [key, control] of local.controls) {
            const {field, indexes, node} = control;
            let visible = field.kind !== 'hidden' && local.rule(field.visible,indexes) && !local.rule(field.hidden,indexes);
            if (field.visibleJs) visible &&= Boolean(window.Alpine.evaluate(node, field.visibleJs,{scope:{$get:path => local.value(resolve(field.path,path,indexes))}}));
            if (field.hiddenJs) visible &&= !window.Alpine.evaluate(node, field.hiddenJs,{scope:{$get:path => local.value(resolve(field.path,path,indexes))}});
            node.hidden = !visible;
            if (control.errorNode) {
                const messages=local.errors[local.id]?.[at(field.path,indexes)] || [];
                control.errorNode.textContent=messages.join(' ');control.errorNode.hidden=!messages.length;
            }
            node.dispatchEvent(new CustomEvent('workflow-local-field-refresh',{detail:{key}}));
        }
    };
    function append(fields, parent, indexes) {
        for (const field of fields) {
            if (field.kind === 'hidden') continue;
            const key = field.path + ':' + indexes.join('.') + ':' + local.controls.size;
            let node;
            if (['group','section','repeater'].includes(field.kind)) {
                node = el('div',field.kind === 'section' ? 'fi-section fi-section-not-contained' : field.kind === 'repeater' ? 'fi-fo-field fi-fo-repeater' : 'fi-sc fi-grid');
                if (field.attributes?.class) node.className += ' ' + field.attributes.class;
                const content = el('div',field.kind === 'section' ? 'fi-section-content fi-sc fi-grid' : 'fi-sc fi-grid');
                grid(content,field.columns);
                if (field.kind === 'section' && local.rule(field.label,indexes)) {
                    const heading = el(field.collapsible?'button':'header','fi-section-header');
                    const title=el('div','fi-section-header-text-ctn');title.append(el('h3','fi-section-header-heading',local.rule(field.label,indexes)));heading.append(title);
                    if (field.collapsible) {
                        heading.append(local.template('collapse'));
                        const sectionKey=local.id+':'+key;
                        heading.type='button'; content.hidden=local.sections.get(sectionKey) ?? Boolean(local.rule(field.collapsed,indexes));
                        heading.setAttribute('aria-expanded',String(!content.hidden));
                        heading.addEventListener('click',()=>{content.hidden=!content.hidden;local.sections.set(sectionKey,content.hidden);heading.setAttribute('aria-expanded',String(!content.hidden));});
                    }
                    node.append(heading);
                }
                node.append(content);
                if (field.kind === 'repeater') {
                    if (!field.labelHidden) content.append(el('label','fi-fo-field-label-content',local.rule(field.label,indexes) || ''));
                    (local.value(at(field.path,indexes)) || []).forEach((row,index) => {
                        const item = el('div','fi-fo-repeater-item fi-fo-repeater-item-has-header');
                        const header = el('div','fi-fo-repeater-item-header');
                        if (field.reorderable) {
                            const reorder=local.template('reorder');
                            reorder.addEventListener('dragstart',event=>{local.draggedRow={key, index};event.dataTransfer.effectAllowed='move';event.dataTransfer.setData('text/plain',String(index));event.stopPropagation();});
                            reorder.addEventListener('dragend',()=>{local.draggedRow=null;});
                            item.addEventListener('dragover',event=>{if(local.draggedRow?.key===key) event.preventDefault();});
                            item.addEventListener('drop',event=>{if(local.draggedRow?.key!==key) return;event.preventDefault();event.stopPropagation();local.reorder(field,indexes,local.draggedRow.index,index);local.draggedRow=null;});
                            const start=el('ul','fi-fo-repeater-item-header-start-actions'),handle=el('li');handle.append(reorder);start.append(handle);header.append(start);
                        }
                        const remove = local.template('remove');
                        remove.disabled=(local.value(at(field.path,indexes)) || []).length <= (field.minItems || 0);
                        const end=el('ul','fi-fo-repeater-item-header-end-actions'),removeItem=el('li');removeItem.append(remove);end.append(removeItem);
                        remove.addEventListener('click',()=>local.remove(field,indexes,index)); header.append(end); item.append(header);
                        const body = el('div','fi-fo-repeater-item-content fi-sc fi-grid'); grid(body,field.columns);
                        append(field.children,body,[...indexes,index]); item.append(body); content.append(item);
                    });
                    const add = el('button','fi-btn fi-size-sm',field.addLabel || 'Добавить'); add.type='button';
                    add.addEventListener('click',()=>local.add(field,indexes)); content.append(add);
                } else append(field.children,content,indexes);
            } else if (field.kind === 'view') {
                node = el('div'); node.innerHTML=field.html || '';
            } else {
                node = local.template(field.kind);
                if (!node) throw new Error('Неизвестный тип поля: '+field.kind);
                node.setAttribute('x-data','local.field('+json(key)+')');
                if (field.kind === 'select' && (!field.native || field.searchable || field.multiple)) node.querySelector('[data-local-select]').setAttribute('x-data','local.selectField('+json(key)+')');
                if (field.kind === 'code') node.querySelector('[data-local-code]').setAttribute('x-data','local.codeField('+json(key)+')');
            }
            node.classList.add('fi-grid-col');
            for (const [breakpoint,span] of Object.entries(field.span || {default:1})) if(span) {
                node.style.setProperty('--col-span-'+breakpoint,span==='full'?'1 / -1':'span '+span+' / span '+span);
                if(breakpoint!=='default') node.classList.add(breakpoint+':fi-grid-col-span');
            }
            parent.append(node);
            let errorNode=null;
            if(!['group','section','view'].includes(field.kind)) {errorNode=el('p','fi-fo-field-wrp-error-message');errorNode.setAttribute('role','alert');errorNode.hidden=true;node.append(errorNode);}
            local.controls.set(key,{field,indexes,node,errorNode});
        }
    }
    local.field = key => {
        const control = local.controls.get(key), {field,indexes} = control;
        const path = at(field.path,indexes);
        const value = local.value(path) ?? null;
        const options = () => local.rule(field.options,indexes) || {};
        const labels = () => local.rule(field.label,indexes) || '';
        let component = field.kind === 'value' ? window.workflowValueField(value,field.relativePath ? 'mountedActions.0.data.items' : null,field.collectionPath ? 'mountedActions.0.data.rules' : null)
            : field.kind === 'headers' ? window.workflowHeadersField(value)
            : {state:value};
        const originalInit = component.init;
        Object.assign(component,{
            field, path, controlKey:key, label:labels(), state:value, options:options(),
            hasOptions: Boolean(local.rule(field.hasOptions,indexes)),
            placeholder:local.rule(field.placeholder,indexes) || '',
            required:local.rule(field.required,indexes),
            helper:local.rule(field.helper,indexes) || '',
            labelHidden:field.labelHidden,
            sources:[], customFields:local.boot.customFields,
            $wire:{$get:name => local.value(name.replace(/^mountedActions\.0\.data\./,'')),$set:(name,item) => local.set(name.replace(/^mountedActions\.0\.data\./,''),item)},
            init() {
                this.sources = local.sources();
                originalInit?.call(this);
                this.$watch('state', state => local.set(path,state));
            },
            refreshField() {
                this.label=labels(); this.options=options();
                this.hasOptions=Boolean(local.rule(field.hasOptions,indexes));
                this.placeholder=local.rule(field.placeholder,indexes)||'';
                this.required=local.rule(field.required,indexes);
                this.helper=local.rule(field.helper,indexes)||'';
                this.sources=local.sources();this.customFields=local.boot.customFields;
                const next=local.value(path) ?? null;
                if (!equal(this.state,next)) this.state=clone(next);
                if (field.kind==='select' && (!field.native || field.searchable || field.multiple)) this.$el.querySelector('[data-local-select]')?.dispatchEvent(new CustomEvent('workflow-local-select-refresh'));
            },
        });
        return component;
    };
    local.selectField = key => {
        const {field,indexes} = local.controls.get(key), path=at(field.path,indexes);
        const initial=local.value(path) ?? (field.multiple ? [] : null);
        const options = () => Object.entries(local.rule(field.options,indexes)||{}).map(([value,label]) => ({value:String(value),label:String(label),isDisabled:false}));
        const label = () => local.value(path) == null || local.value(path)==='' ? null : options().find(item=>String(item.value)===String(local.value(path)))?.label ?? String(local.value(path));
        const widget = local.selectFactory({
            canOptionLabelsWrap:true,canSelectPlaceholder:true,
            getOptionLabelUsing:async()=>label(), getOptionLabelsUsing:async()=>options().filter(item=>(local.value(path)||[]).map(String).includes(item.value)),
            getOptionsUsing:async()=>options(),getSearchResultsUsing:async query=>options().filter(item=>item.label.toLocaleLowerCase().includes(query.toLocaleLowerCase())),
            hasDynamicOptions:true,hasDynamicSearchResults:false,hasInitialNoOptionsMessage:false,
            initialOptionLabel:label(),initialOptionLabels:field.multiple?options().filter(item=>(local.value(path)||[]).map(String).includes(item.value)):[],
            initialState:initial,state:initial,
            isAutofocused:false,isDisabled:false,isHtmlAllowed:false,isMultiple:Boolean(field.multiple),isReorderable:false,isSearchable:Boolean(field.searchable),
            livewireId:'browser-inspector',statePath:path,loadingMessage:'Загрузка…',maxItems:null,maxItemsMessage:'',
            noOptionsMessage:'Нет вариантов',noSearchResultsMessage:'Ничего не найдено',options:options(),optionsLimit:50,
            placeholder:local.rule(field.placeholder,indexes)||'Выберите значение',position:null,searchDebounce:0,searchingMessage:'Поиск…',searchPrompt:'Поиск',searchableOptionFields:['label'],
        });
        const originalInit=widget.init;
        widget.init=function() { originalInit?.call(this); this.$watch('state',value=>local.set(path,value)); };
        widget.refreshLocalSelect=function() {
            const next=options();
            if (!equal(this.select.options,next)) {this.select.options=next;this.select.originalOptions=clone(next);this.select.renderOptions();}
            const state=local.value(path) ?? (field.multiple ? [] : null);
            if (!equal(this.state,state)) this.state=state;
        };
        return widget;
    };
    local.codeField = key => {
        const {field,indexes} = local.controls.get(key),path=at(field.path,indexes);
        const editor=local.codeFactory({canWrap:false,isDisabled:false,isLive:false,isLiveDebounced:false,isLiveOnBlur:false,liveDebounce:null,language:'javascript',state:local.value(path)||''});
        const init=editor.init;
        Object.assign(editor,window.workflowJavascriptFormatting(false));
        editor.init=function(){init?.call(this);this.$watch('state',state=>local.set(path,state));};
        return editor;
    };
    local.createOption=async()=>{
        local.flush();local.creatingOption=true;
        try {await local.wire.openWorkflowInspectorCreateOption(local.id);}
        catch {local.creatingOption=false;panel.error='Не удалось открыть создание подключения.';}
    };
    local.serverUpdated=()=>{
        local.sourceCache=null;
        const source=root.querySelector('[data-local-inspector-boot]')?.textContent;
        if(source && source!==local.bootSource) {
            local.bootSource=source;local.boot=JSON.parse(source);
            if(local.id && local.node()) {
                const schema=local.boot.schemas[(local.id.startsWith('trigger')?'trigger:':'action:')+local.type];
                if(!equal(local.schema,schema)) {local.schema=schema;local.render();}
                local.refresh();local.renderInput();
            }
        }
        const action=local.wire.mountedActions?.[0];
        if(local.creatingOption && local.wire.mountedActions?.length===1 && action?.name==='configureWorkflowAction') {
            local.creatingOption=false;
            local.set('credential_id',action.data?.credential_id ?? null);
            local.wire.$set('mountedActions',[],false);
        }
        for(const id of Object.keys(local.errors)) if(!local.wire.workflowLocalInspectorDirty?.[id]) delete local.errors[id];
        if(local.id && local.node() && !local.wire.workflowLocalInspectorDirty?.[local.id]) {
            local.base=clone(local.node().config || {});panel.error='';
        }
    };
    local.bootSource=root.querySelector('[data-local-inspector-boot]')?.textContent;
    return local;
}

function resolve(field,path,indexes) {
    const parts=at(field,indexes).split('.'); parts.pop();
    while(path.startsWith('../')) {parts.pop();path=path.slice(3);}
    return [...parts,path].filter(Boolean).join('.');
}

function grid(node,columns) {
    node.style.setProperty('--cols-default','repeat(1,minmax(0,1fr))');
    for(const [breakpoint,count] of Object.entries(columns || {})) if(count) {
        node.style.setProperty('--cols-'+breakpoint,'repeat('+count+',minmax(0,1fr))');
        if(breakpoint!=='default') node.classList.add(breakpoint+':fi-grid-cols');
    }
}
