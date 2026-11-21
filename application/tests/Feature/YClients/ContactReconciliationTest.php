<?php

namespace Tests\Feature\YClients;

use App\Models\Core\Account;
use App\Models\Integrations\YClients\Client;
use App\Services\amoCRM\Client as AmoClient;
use App\Services\YClients\Contacts;
use App\Services\YClients\EntityCreateGuard;
use App\Services\YClients\UnconfirmedCreateException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Ufee\Amo\Models\Contact;
use Ufee\Amo\Oauthapi;

class ContactReconciliationTest extends TestCase
{
    private Client $client;
    private int $posts = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default'=>'sqlite','database.connections.sqlite.database'=>':memory:']);
        DB::purge('sqlite'); Http::preventStrayRequests();
        (require database_path('migrations/2026_10_14_120000_create_yclients_create_receipts_table.php'))->up();
        Schema::create('yclients_clients',function(Blueprint $table):void {
            $table->id(); foreach(['user_id','account_id','setting_id','company_id','client_id'] as $key) $table->integer($key);
            $table->integer('contact_id')->nullable();$table->string('name')->nullable();
            $table->string('phone')->nullable();$table->string('email')->nullable();$table->timestamps();
        });
        $this->client=Client::forceCreate(['user_id'=>2,'account_id'=>1,'setting_id'=>3,'company_id'=>4,
            'client_id'=>5,'contact_id'=>10,'phone'=>'+79261234567']);
        EntityCreateGuard::forContact($this->client)->create(fn()=>(object)['id'=>10]);
    }

    public function test_merged_contact_is_relinked_without_creating_a_contact():void
    {
        $api=$this->api(fn()=>['_embedded'=>['contacts'=>[$this->row(20)]]]);
        $this->assertSame(20,(int)Contacts::updateOrCreate($this->client,$api)->id);
        $this->assertSame(20,(int)$this->client->fresh()->contact_id);
        $this->assertSame(0,$this->posts);
        $this->assertSame(10,(int)DB::table('yclients_create_receipts')->value('remote_id'));
    }

    public function test_ambiguous_contacts_keep_the_original_guard_and_do_not_create():void
    {
        $this->assertUnconfirmed($this->api(fn()=>['_embedded'=>['contacts'=>[$this->row(20),$this->row(21)]]]));
    }

    public function test_empty_search_never_permits_repeating_an_uncertain_post():void
    {
        $this->assertUnconfirmed($this->api(fn()=>[]));
    }

    public function test_search_failure_does_not_change_or_create_a_contact():void
    {
        $api=$this->api(fn()=>throw new \RuntimeException('Search unavailable',503));
        try{Contacts::updateOrCreate($this->client,$api);$this->fail('Read failures must stop reconciliation.');}
        catch(\RuntimeException $e){$this->assertSame(503,$e->getCode());}
        $this->assertSame(10,(int)$this->client->fresh()->contact_id);$this->assertSame(0,$this->posts);
    }

    public function test_another_account_cannot_be_bound():void
    {
        $api=$this->api(fn()=>['_embedded'=>['contacts'=>[array_replace($this->row(20),['account_id'=>999])]]]);
        $this->expectExceptionMessage('Unexpected contact account');
        try{Contacts::updateOrCreate($this->client,$api);}
        finally{$this->assertSame(10,(int)$this->client->fresh()->contact_id);$this->assertSame(0,$this->posts);}
    }

    public function test_all_pages_are_checked_before_selecting_a_unique_contact():void
    {
        $api=$this->api(fn(array $query)=>($query['page']??1)===1
            ?['_embedded'=>['contacts'=>[$this->row(20)]],'_links'=>['next'=>['href'=>'next']]]
            :['_embedded'=>['contacts'=>[$this->row(21)]]]);
        $this->assertUnconfirmed($api);
    }

    public function test_email_disambiguates_shared_phone_and_duplicate_search_hits():void
    {
        $this->client->update(['email'=>'client@example.test']);
        $api=$this->api(fn()=>['_embedded'=>['contacts'=>[
            $this->row(20,'client@example.test'),$this->row(21,'another@example.test')]]]);
        $this->assertSame(20,(int)Contacts::updateOrCreate($this->client,$api)->id);
        $this->assertSame(0,$this->posts);
    }

    public function test_fuzzy_search_hit_with_another_phone_is_not_selected():void
    {
        $row=$this->row(20);$row['custom_fields_values'][0]['values'][0]['value']='+79267654321';
        $this->assertUnconfirmed($this->api(fn()=>['_embedded'=>['contacts'=>[$row]]]));
    }

    private function assertUnconfirmed(AmoClient $api):void
    {
        try{Contacts::updateOrCreate($this->client,$api);$this->fail('Ambiguity must remain blocked.');}
        catch(UnconfirmedCreateException){$this->assertSame(10,(int)$this->client->fresh()->contact_id);}
        $this->assertSame(0,$this->posts);
        $this->assertSame(10,(int)DB::table('yclients_create_receipts')->value('remote_id'));
    }

    private function row(int $id,?string $email=null):array
    {
        $fields=[['field_code'=>'PHONE','values'=>[['value'=>'+7 (926) 123-45-67']]]];
        if($email)$fields[]=['field_code'=>'EMAIL','values'=>[['value'=>$email]]];
        return ['id'=>$id,'account_id'=>42,'is_deleted'=>false,'custom_fields_values'=>$fields];
    }

    private function api(callable $search):AmoClient
    {
        $contact=$this->createMock(Contact::class);
        $contact->method('__get')->willReturnCallback(fn($key)=>$key==='id'?20:null);
        $contact->method('cf')->willReturn(new class{public function setValue($value):void{}});
        $contact->method('save')->willReturn(true);
        $onCreate=function():never{$this->posts++;throw new \RuntimeException('New contacts are forbidden during reconciliation.');};
        $service=new class($contact,$onCreate){
            public function __construct(private Contact $contact,private \Closure $onCreate){}
            public function find(int $id):?Contact{return $id===20?$this->contact:null;}
            public function create():never{($this->onCreate)();}
        };
        $api=$this->createMock(AmoClient::class);$api->account=(new Account)->forceFill(['id'=>1,'user_id'=>2,'amo_account_id'=>42]);
        $api->service=$this->createMock(Oauthapi::class);$api->service->method('__call')->willReturn($service);
        $api->method('requestV4')->willReturnCallback(function($method,$path,$payload=[],$query=[])use($search):array{
            $this->assertSame('GET',$method);
            return $path==='/api/v4/account'?['id'=>42]:$search($query);
        });
        return $api;
    }
}
