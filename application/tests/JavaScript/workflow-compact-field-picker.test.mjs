import test from 'node:test';
import assert from 'node:assert/strict';
import {compactPickerFields, compactPickerPosition} from '../../resources/js/workflow-compact-field-picker.mjs';

const source = {
    id: 'contact-find', name: 'Получить контакты',
    fields: [
        {label: 'name', key: '.name', expression: '{{ $("Получить контакты").name }}', available: false},
        {label: 'author', key: '.author', expression: '{{ $("Получить контакты").author }}'},
        {label: 'name', key: '.author.name', expression: '{{ $("Получить контакты").author.name }}'},
        {label: 'attachment', key: '.attachment', expression: null},
    ],
};
test('compact picker searches localized labels and nested paths without needing recorded values', () => {
    assert.deepEqual(compactPickerFields(source, 'иМя').map(item => item.key), ['.name', '.author.name']);
    assert.equal(compactPickerFields(source, 'author name')[0].expression, source.fields[2].expression);
    assert.deepEqual(compactPickerFields(source, 'attachment'), []);
});

test('compact picker keeps collections intact and uses existing first-item syntax for item paths', () => {
    const array = {
        fields: [{label:'Результат', key:'', type:'array', expression:'{{ $("Контакты") }}', item_fields:['id','author.name','bad()']}],
    };
    assert.deepEqual(compactPickerFields(array).map(item => item.expression), [
        '{{ $("Контакты") }}', '{{ $("Контакты").first().id }}', '{{ $("Контакты").first().author.name }}',
    ]);
    assert.deepEqual(compactPickerFields(array, '', true).map(item => item.expression), ['{{ $("Контакты") }}']);
});

test('popup aligns beside the inspector, flips at edges, and fits narrow viewports', () => {
    const size = {width:295, height:229};
    const screen = {width:1280,height:720};
    assert.deepEqual(compactPickerPosition({left:700,right:1128},{top:470,bottom:504,right:1112},screen,size), {left:397,top:380});
    assert.deepEqual(compactPickerPosition({left:32,right:460},{top:200,bottom:234,right:444},screen,size), {left:468,top:110});
    assert.deepEqual(compactPickerPosition({left:8,right:352},{top:520,bottom:554,right:336},{width:360,height:640},size), {left:41,top:283});
});

test('business entity hints give IDs and names meaningful labels without guessing unrelated operations', async () => {
    const {compactPickerEntity}=await import('../../resources/js/workflow-compact-field-picker.mjs');
    assert.equal(compactPickerEntity('amocrm_get_contact'),'contact');
    assert.equal(compactPickerEntity('amocrm_create_lead'),'lead');
    assert.equal(compactPickerEntity('amocrm_read',{operation:'companies.list'}),'company');
    assert.equal(compactPickerEntity('amocrm_read',{operation:'leads.notes'}),null);
    const fields=compactPickerFields({entity:'contact',available:true,fields:[
        {label:'id',key:'.id',type:'int',value:42,expression:'{{ $("Контакт").id }}'},
        {label:'name',key:'.name',type:'string',value:'Анна',expression:'{{ $("Контакт").name }}'},
        {label:'name',key:'.author.name',type:'string',value:'Иван',expression:'{{ $("Контакт").author.name }}'},
    ]});
    assert.deepEqual(fields.map(field=>field.label),['ID контакта','Имя контакта','Имя']);
    assert.deepEqual(fields.map(field=>field.preview),['42','Анна','Иван']);
});

test('scalar fields exclude entire objects and lists while collection controls keep the original list expression', () => {
    const source={id:'contacts',name:'Контакты',entity:'contact',available:true,fields:[
        {label:'Результат',key:'',type:'array',count:2,expression:'{{ $("Контакты") }}',item_fields:['id','name','author','author.name']},
    ]};
    const fields=compactPickerFields(source,'',false,{allowStructured:false});
    assert.deepEqual(fields.map(field=>field.expression),[
        '{{ $("Контакты").first().id }}','{{ $("Контакты").first().name }}','{{ $("Контакты").first().author.name }}',
    ]);
    assert.equal(fields[0].label,'ID контакта');
    assert.ok(fields.every(field=>field.detail==='Первый в списке'));
    const collection=compactPickerFields(source,'',true);
    assert.equal(collection[0].label,'Список контактов');
    assert.equal(collection[0].preview,'2 элемента');
    assert.equal(collection[0].expression,'{{ $("Контакты") }}');
    assert.ok(!JSON.stringify(collection).includes('Весь список'));
});

test('examples keep zero, false and null distinct and never invent values for an untested step', () => {
    const sample={available:true,fields:[
        {label:'price',key:'.price',type:'int',value:0,expression:'zero'},
        {label:'success',key:'.success',type:'bool',value:false,expression:'false'},
        {label:'name',key:'.name',type:'null',value:null,expression:'null'},
        {label:'name',key:'.pending',available:false,value:null,expression:'pending'},
    ]};
    assert.deepEqual(compactPickerFields(sample).map(field=>field.preview),['0','Нет','Не заполнено','']);
    assert.equal(compactPickerFields(sample,'нет')[0].expression,'false');
    assert.equal(compactPickerFields({...sample,available:false})[0].preview,'');
});

test('grouped search covers step names, field names and example values and omits empty sources', async () => {
    const {compactPickerGroups}=await import('../../resources/js/workflow-compact-field-picker.mjs');
    const sources=[
        {id:'empty',name:'Ручной запуск',fields:[{type:'object',key:'',count:0,expression:'{{ $json }}'}]},
        {id:'contact',name:'Получить контакт',entity:'contact',available:true,fields:[{label:'name',key:'.name',value:'Анна',expression:'contact.name'}]},
        {id:'lead',name:'Получить сделку',entity:'lead',available:true,fields:[{label:'name',key:'.name',value:'Заказ',expression:'lead.name'}]},
    ];
    assert.deepEqual(compactPickerGroups(sources,'',{allowStructured:false}).map(group=>group.id),['lead','contact']);
    assert.equal(compactPickerGroups(sources,'Анна')[0].id,'contact');
    assert.equal(compactPickerGroups(sources,'сделку')[0].fields[0].label,'Название сделки');
    assert.equal(compactPickerGroups(sources,'неизвестное').length,0);
});

test('step groups show six fields initially, reveal the rest on request and search across closed steps', async () => {
    const {workflowCompactFieldPicker}=await import('../../resources/js/workflow-compact-field-picker.mjs');
    const picker=workflowCompactFieldPicker();picker.$nextTick=callback=>callback();picker.positionCompactPicker=()=>{};
    picker.pickerSources=[{id:'one',name:'Первый шаг',fields:Array.from({length:9},(_,i)=>({label:'Поле '+i,key:'.value'+i,expression:'one.'+i}))},
        {id:'two',name:'Второй шаг',fields:[{label:'Телефон',key:'.phone',expression:'two.phone'}]}];
    picker.pickerExpanded=['one'];
    assert.equal(picker.pickerFields.length,6);
    picker.showAllPickerFields('one');assert.equal(picker.pickerFields.length,9);
    picker.togglePickerSource('two');assert.deepEqual(picker.pickerFields.map(field=>field.expression),['two.phone']);
    picker.pickerQuery='Поле 8';picker.resetPickerSearch();assert.equal(picker.pickerFields[0].expression,'one.8');
    assert.equal(picker.pickerFields[0].index,0);
});

test('collection-only picker and empty scalar input have honest empty results', async () => {
    const {compactPickerGroups}=await import('../../resources/js/workflow-compact-field-picker.mjs');
    const source={id:'start',name:'Ручной запуск',available:false,fields:[{key:'',type:'array',count:0,item_fields:[],expression:'{{ $json }}'}]};
    assert.deepEqual(compactPickerGroups([source],'',{allowStructured:false}),[]);
    const groups=compactPickerGroups([source],'',{collectionOnly:true});
    assert.equal(groups[0].fields[0].label,'Список элементов');
    assert.equal(groups[0].fields[0].preview,'');
});

test('recorded list fields show a bounded first-item example without exposing sensitive nested payloads', async () => {
    const {inspectorSource}=await import('../../resources/js/workflow-local-inspector.mjs');
    const source={...inspectorSource('contacts','Контакты',[{id:42,name:'Анна',active:false,balance:0,extra:{token:'never-show',city:'Калининград'}}],true),entity:'contact'};
    const fields=compactPickerFields(source,'',false,{allowStructured:false});
    assert.equal(fields.find(field=>field.key==='id').preview,'42');
    assert.equal(fields.find(field=>field.key==='active').preview,'Нет');
    assert.equal(fields.find(field=>field.key==='balance').preview,'0');
    assert.equal(fields.find(field=>field.key==='extra'),undefined);
    assert.equal(JSON.stringify(source).includes('never-show'),false);
});

test('entity type controls offer compatible type values rather than unrelated IDs or names', () => {
    const source={available:true,fields:[
        {label:'id',key:'.id',type:'int',value:42,expression:'id'},
        {label:'name',key:'.name',type:'string',value:'Анна',expression:'name'},
        {label:'entity',key:'.entity',type:'string',value:'contacts',expression:'entity'},
    ]};
    const fields=compactPickerFields(source,'',false,{allowStructured:false,target:{choices:{lead:'Сделка',contact:'Контакт'}}});
    assert.deepEqual(fields.map(field=>field.expression),['entity']);
    assert.equal(fields[0].label,'Тип сущности');
    assert.equal(fields[0].preview,'Контакт');
});

test('empty states distinguish no previous step, an untested step and a tested empty result', async () => {
    const {workflowCompactFieldPicker,compactPickerGroups}=await import('../../resources/js/workflow-compact-field-picker.mjs');
    const picker=workflowCompactFieldPicker();picker.pickerSources=[{id:'trigger',available:false,fields:[]}];
    assert.match(picker.pickerEmptyHint,/добавьте шаг/);
    picker.pickerSources.push({id:'previous',available:false,fields:[]});
    assert.match(picker.pickerEmptyHint,/Протестируйте/);
    picker.pickerSources[1].available=true;
    assert.match(picker.pickerEmptyHint,/нет подходящих полей/);
    const empty={id:'previous',available:true,fields:[{key:'',label:'Результат',type:'null',value:null,expression:'{{ $("Шаг") }}'}]};
    assert.deepEqual(compactPickerGroups([empty],'',{allowStructured:false}),[]);
    assert.equal(compactPickerGroups([empty],'',{allowStructured:true})[0].fields[0].preview,'Не заполнено');
});
