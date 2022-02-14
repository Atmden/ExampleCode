<?php

namespace App\Http\Controllers;


use App\Models\BuhJob;
use App\Models\Catalog;
use App\Models\City;
use App\Models\Contract;
use App\Models\ContractAction;
use App\Models\Document;
use App\Models\House;
use App\Models\IncomeSubconto;
use App\Models\Inform;
use App\Models\Nomenclature;
use App\Models\Phone;
use App\Models\Street;
use App\Models\Ticket;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Goutte\Client;
use Illuminate\Support\Facades\Log;

class BuhController extends Controller
{
    private $client;

    private $main_url;
    private $test_main_url;

    //Функция получения групп в справочнике Контрагентов в 1С
    public function get_group_contragent()
    {
        $url = $this->main_url . 'Catalog_Контрагенты?$format=json&$filter=IsFolder eq true and DeletionMark eq false';
        $crawler = $this->client->request('GET', $url);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        if (count($data['value'])) {
            foreach ($data['value'] as $item) {
                Catalog::updateorcreate([
                    'guid' => $item['Ref_Key']
                ], [
                    'title' => $item['Description'],
                    'parent_guid' => $item['Parent_Key'],
                ]);
            }
            $catalog = Catalog::where('parent_guid', '!=', '00000000-0000-0000-0000-000000000000')->get();
            foreach ($catalog as $item) {
                $parent = Catalog::where('guid', $item->parent_guid)->first();
                $item->parent_id = $parent->id;
                $item->save();

            }
        }

        return redirect()->back()->with(['info_message' => 'Данные успешно обновлены']);
    }

    public function update_catalog_contragent($id)
    {
        $catalog = Catalog::find($id);
        if ($catalog == null) {
            $data['result_code'] = 301;
            $data['result_message'] = 'Группа не найдена';
            return $data;
        }
        // Проверяем guid на наличие
        if ($catalog->guid != null) {
            $guid = $catalog->guid;
            $url = $this->main_url . 'Catalog_Контрагенты(guid\'' . $guid . '\')?$format=json';

            $param = [
                'Description' => $this->mb_trim($catalog->title),
                'IsFolder'=> true,
                "Parent_Key" => $catalog->parent_guid,
            ];

            $crawler = $this->client->request('PATCH', $url, array(), array(),
                array('HTTP_CONTENT_TYPE' => 'application/json'), json_encode($param));

            $data = json_decode($this->client->getResponse()->getContent(), true);
            if (isset($data['Ref_Key'])) {

                $data['result_code'] = 0;
                $data['result_message'] = 'Каталог обновлен';
            } else {
                $data['result_message'] = 'Что-то пошло не так...';
                $data['result_code'] = 300;
            }
            return $data;
        } else {
            $data['result_message'] = 'Не увидели guid 1С';
            $data['result_code'] = 303;
            return $data;
        }

    }

    public function create_catalog_contragent($id)
    {
        $catalog = Catalog::find($id);
        if ($catalog == null) {
            $data['result_code'] = 301;
            $data['result_message'] = 'Группа не найдена';
            return $data;
        }

        if ($catalog->guid != null)
        {
            $data['result_code'] = 301;
            $data['result_message'] = 'Группа имеет guid';
            return $data;
        }

            $url = $this->main_url . 'Catalog_Контрагенты?$format=json';


            $param = [
                'Description' => $this->mb_trim($catalog->title),
                'IsFolder'=> true,
                "Parent_Key" => $catalog->parent_guid, // в какую папку добавлять контрагента
            ];
            $crawler = $this->client->request('POST', $url, array(), array(),
                array('HTTP_CONTENT_TYPE' => 'application/json'), json_encode($param));

            $data = json_decode($this->client->getResponse()->getContent(), true);
            Log::debug($data);
            if (isset($data['Ref_Key'])) {
                $catalog->guid = $data['Ref_Key'];

                // Надо сохранить guid без вызова событий у модели
                $catalog->saveQuietly();

                $data['result_code'] = 0;
                $data['result_message'] = 'Клиент добавлен';
            } else {
                $data['result_message'] = 'Что-то пошло не так...';
                $data['result_code'] = 300;
            }
            return $data;




    }

    //Функция по обработке заданий для 1С
    public function run_job()
    {
        // 0. Создаем группы контрагентов
        // 0-1. Обновляем группы контрагентов
        // 1. Создаем клиентов
        // 2. Обновляем клиентов
        // 3. Создаем начисления
        // 4. Обновляем начисления
        // 5. Удаление начисления


        // Получаем задания на создание группы контрагентов
        $jobs = BuhJob::where('job_type', 'App\Models\Catalog')->where('event', 'create')->where('run_job', '0')->get();
        if (!count($jobs)) {
            Log::debug('No Catalog to create');
        }
        foreach ($jobs as $job) {
            if ($job->job != null) {
                $id = $job->job->id;
                Log::debug('Create Catalog -' . $id);
                $result = $this->create_catalog_contragent($id);
                $job->result_code = $result['result_code'];
                $job->result = $result;
                $job->run_job = 1;
                $job->save();
            } else {
                $job->result_code = 700;
                $job->result = 'Object is null';
                $job->run_job = 1;
                $job->save();
            }
        }

        // Получаем задания на обновление группы контрагентов
        $jobs = BuhJob::where('job_type', 'App\Models\Catalog')->where('event', 'update')->where('run_job', '0')->get();
        if (!count($jobs)) {
            Log::debug('No Catalog to update');
        }
        foreach ($jobs as $job) {
            if ($job->job != null) {
                $id = $job->job->id;
                Log::debug('update Catalog -' . $id);
                $result = $this->update_catalog_contragent($id);
                $job->result_code = $result['result_code'];
                $job->result = $result;
                $job->run_job = 1;
                $job->save();
            } else {
                $job->result_code = 700;
                $job->result = 'Object is null';
                $job->run_job = 1;
                $job->save();
            }
        }
        // Получаем задания на создание клиентов
        $jobs = BuhJob::where('job_type', 'App\Models\User')->where('event', 'create')->where('run_job', '0')->get();
        if (!count($jobs)) {
            Log::debug('No user to create');
        }
        foreach ($jobs as $job) {
            if ($job->job != null) {
                $id = $job->job->id;
                Log::debug('Create user -' . $id);
                $result = $this->create_contragent($id);
                $job->result_code = $result['result_code'];
                $job->result = $result;
                $job->run_job = 1;
                $job->save();
            } else {
                $job->result_code = 700;
                $job->result = 'Object is null';
                $job->run_job = 1;
                $job->save();
            }
        }

        // Полчаем задания на обновление клиентов
        $jobs = BuhJob::where('job_type', 'App\Models\User')->where('event', 'update')->where('run_job', '0')->get();
        if (!count($jobs)) {
            Log::debug('No user to update');
        }

        foreach ($jobs as $job) {
            if ($job->job != null) {
                $id = $job->job->id;
                Log::debug('Update user -' . $id);
                $result = $this->update_contragent($id);
                $job->result_code = $result['result_code'];
                $job->result = $result;
                $job->run_job = 1;
                $job->save();
            } else {
                $job->result_code = 700;
                $job->result = 'Object is null';
                $job->run_job = 1;
                $job->save();
            }
        }

        // Получаем задания на создание договоров
        $jobs = BuhJob::where('job_type', 'App\Models\Contract')->where('event', 'create')->where('run_job', '0')->get();
        if (!count($jobs)) {
            Log::debug('No Contract to create');
        }

        foreach ($jobs as $job) {
            if ($job->job != null) {
                $id = $job->job->id;
                Log::debug('Contract create -' . $id);
                $result = $this->create_dogovor_contragent($id);
                $job->result_code = $result['result_code'];
                $job->result = $result;
                $job->run_job = 1;
                $job->save();
            } else {
                $job->result_code = 700;
                $job->result = 'Object is null';
                $job->run_job = 1;
                $job->save();
            }
        }

        // Получаем задания на обновление договоров в 1С
        $jobs = BuhJob::where('job_type', 'App\Models\Contract')->where('event', 'update')->where('run_job', '0')->get();
        if (!count($jobs)) {
            Log::debug('No Contract to update');
        }

        foreach ($jobs as $job) {
            if ($job->job != null) {
                $id = $job->job->id;
                Log::debug('Contract update -' . $id);
                $result = $this->update_dogovor_contragent($id);
                $job->result_code = $result['result_code'];
                $job->result = $result;
                $job->run_job = 1;
                $job->save();
            } else {
                $job->result_code = 700;
                $job->result = 'Object is null';
                $job->run_job = 1;
                $job->save();
            }
        }

        // Получаем задания на создание документов в 1С
        $jobs = BuhJob::where('job_type', 'App\Models\ContractAction')->where('event', 'create')->where('run_job', '0')->get();
        if (!count($jobs)) {
            Log::debug('No ContractAction to create');
        }

        foreach ($jobs as $job) {
            if ($job->job != null) {
                $id = $job->job->id;
                Log::debug('ContractAction create -' . $id);
                // Если тип документа "Реализция ТМЗ"
                if ($job->job->contract_actiontype->action == 1) {
                    $result = $this->create_sale_of_goods($id);
                }
                $job->result_code = $result['result_code'];
                $job->result = $result;
                $job->run_job = 1;
                $job->save();
            } else {
                $job->result_code = 700;
                $job->result = 'Object is null';
                $job->run_job = 1;
                $job->save();
            }
        }

        // Получаем задания на проведение документов в 1С
        $jobs = BuhJob::where('job_type', 'App\Models\ContractAction')->where('event', 'posted')->where('run_job', '0')->get();
        if (!count($jobs)) {
            Log::debug('No ContractAction to posted');
        }

        foreach ($jobs as $job) {
            if ($job->job != null) {
                $id = $job->job->id;
                Log::debug('ContractAction posted -' . $id);
                // Если тип документа "Реализция ТМЗ"
                if ($job->job->contract_actiontype->action == 1) {
                    $result = $this->posted_action($id);
                }
                $job->result_code = $result['result_code'];
                $job->result = $result;
                $job->run_job = 1;
                $job->save();
            } else {
                $job->result_code = 700;
                $job->result = 'Object is null';
                $job->run_job = 1;
                $job->save();
            }
        }

        // Получаем задания на обновления документов в 1С
        $jobs = BuhJob::where('job_type', 'App\Models\ContractAction')->where('event', 'update')->where('run_job', '0')->get();
        if (!count($jobs)) {
            Log::debug('No ContractAction to update');
        }

        foreach ($jobs as $job) {
            if ($job->job != null) {
                $id = $job->job->id;
                Log::debug('ContractAction update -' . $id);
                // Если тип документа "Реализция ТМЗ"
                if ($job->job->contract_actiontype->action == 1) {
                    $result = $this->update_sale_of_goods($id);
                }
                $job->result_code = $result['result_code'];
                $job->result = $result;
                $job->run_job = 1;
                $job->save();
            } else {
                $job->result_code = 700;
                $job->result = 'Object is null';
                $job->run_job = 1;
                $job->save();
            }
        }

        // Получаем задания на удаление документов в 1С
        $jobs = BuhJob::where('job_type', 'App\Models\ContractAction')->where('event', 'delete')->where('run_job', '0')->get();
        if (!count($jobs)) {
            Log::debug('No ContractAction to delete');
        }


        foreach ($jobs as $job) {
            if ($job->job()->onlyTrashed()->first() != null) {
                $id = $job->job()->onlyTrashed()->first()->id;
                Log::debug('ContractAction delete -' . $id);
                // Если тип документа "Реализция ТМЗ"
                if ($job->job()->onlyTrashed()->first()->contract_actiontype->action == 1) {
                    $result = $this->delete_sale_of_goods($id);

                }
                $job->result_code = $result['result_code'];
                $job->result = $result;
                $job->run_job = 1;
                $job->save();
            } else {
                $job->result_code = 700;
                $job->result = 'Object is null';
                $job->run_job = 1;
                $job->save();
            }
        }

        Log::debug('Complete run_job');
    }


    public function __construct(Client $client)
    {
        $this->client = $client;
        $this->main_url = env('BUH_BASE_PROTOCOL') . '://' . env('BUH_BASE_LOGIN') . ':' . env('BUH_BASE_PASS') . '@' . env('BUH_BASE_IP') . ':' . env('BUH_BASE_PORT') . '/' . env('BUH_BASE_SITE') . '/odata/standard.odata/';
        $this->test_main_url = env('BUH_BASE_TEST_PROTOCOL') . '://' . env('BUH_BASE_TEST_LOGIN') . ':' . env('BUH_BASE_TEST_PASS') . '@' . env('BUH_BASE_TEST_IP') . ':' . env('BUH_BASE_TEST_PORT') . '/' . env('BUH_BASE_TEST_SITE') . '/odata/standard.odata/';
    }


    public function fix_not_found()
    {
        $nots = Inform::where('comment','Не найден User')->get();

        foreach ($nots as $not)
        {
            $data = $this->get_contragent_iin($not->iin,'ФизЛицо');
            dd($data);
        }

    }

    //Синхронизация пользователей с 1С, которые были созданы и там и тут
    public function sync_contragent()
    {
        $users = User::where('created_at', '>', '2021-04-22')->get();
        Log::debug($users->count());
        foreach ($users as $user) {
            Log::debug('Search - ' . $user->iin);
            $data = $this->get_contragent_iin($user->iin, $user->usertype->buh_param);
            if (isset($data['value'])) {
                if (count($data['value'])) {
                    foreach ($data['value'] as $item) {
                        if ($item['Ref_Key'] != $user->guid) {
                            Log::debug('Update user!');
                            $user->guid = $item['Ref_Key'];
//                            $user->code = $item['Code'];
                            $user->saveQuietly();

                        }
                    }

                } else {
                    Log::debug('Not found - ' . $user->iin);

                }
            }
        }
    }

    // Синхронизация групп к клиентам из 1С у тех клиенов у кого группа не указана
    public function sync_catalog_contragent()
    {
        $users = User::where('created_at', '>', '2021-04-22')->get();
        Log::debug($users->count());
        foreach ($users as $user) {
            $data = $this->get_contragent_iin($user->iin, $user->usertype->buh_param);
            if (count($data['value'])) {
                foreach ($data['value'] as $item) {
                    if (isset($item['Ref_Key'])) {

                        $user->catalog_guid = $item['Parent_Key'];
                        if ($item['Parent_Key'] != '00000000-0000-0000-0000-000000000000') {
                            $catalog = Catalog::where('guid', $item['Parent_Key'])->first();
                            if ($catalog != null) {
                                Log::debug('Update user');
                                $user->catalog_id = $catalog->id;

                            } else {
                                Log::debug('Not found catalog');
                            }
                        }
                        $user->saveQuietly();
                    }
                }
            } else {
                Log::debug('Not found - ' . $user->iin);
            }
        }

    }

    // Получание контрагента из 1С по user_id
    public function get_contragent($id = 18849)
    {
        $user = User::find($id);
        $data = null;
        if ($user != null) {
            $guid = $user->guid;

            //Если есть guid - получаем данные по guid
            // Иначе получаем данные по ИИН и наименованию
            if ($guid != null) {
                $url = $this->main_url . 'Catalog_Контрагенты(guid\'' . $guid . '\')?$format=json';
                $crawler = $this->client->request('GET', $url);
                $data = json_decode($this->client->getResponse()->getContent(), true);
            } else {
                $iin = $user->iin;
                //$fio = $this->mb_trim($user->fio);
                if ($iin != null) {
                    $url = $this->main_url . 'Catalog_Контрагенты?$format=json&$filter=ИдентификационныйКодЛичности eq \'' . $iin . '\' and DeletionMark eq false';
                    $crawler = $this->client->request('GET', $url);
                    $data = json_decode($this->client->getResponse()->getContent(), true);
                    //Log::debug($data);
                }
            }
        }
        return $data;
    }


    public function fix_nullable_user()
    {
        $users = User::where('usertype_id',null)->get();
        foreach ($users as $user)
        {
            $data = $this->get_contragent_guid($user->guid);
            if ($data['ЮрФизЛицо'] == 'ФизЛицо') {
                $fio = explode(' ', trim($data['НаименованиеПолное']));
                $user->usertype_id = 1;
                $user->family = $fio[0];
                if (isset($fio[1]))
                    $user->fname = $fio[1];
                if (isset($fio[2]))
                    $user->fathername = $fio[2];


            }
            else
            {
                $user->family = trim($data['НаименованиеПолное']);
                $user->fname = null;
                $user->fathername = null;
                $user->usertype_id = 2;
            }
            $user->saveQuietly();
        }
    }

    // Получание контрагента из 1С по его GUID
    public function get_contragent_guid($guid = null)
    {

        $data = null;
        if ($guid != null) {
            $url = $this->main_url . 'Catalog_Контрагенты(guid\'' . $guid . '\')?$format=json';
            $crawler = $this->client->request('GET', $url);
            $data = json_decode($this->client->getResponse()->getContent(), true);
        }

        return $data;
    }

    // Получание контрагента из 1С по его iin и типу клиента
    public function get_contragent_iin($iin = null, $type = null)
    {

        $data = null;
        if ($iin != null) {
            $url = $this->main_url . 'Catalog_Контрагенты?$format=json&$filter=ИдентификационныйКодЛичности eq \'' . $iin . '\' and DeletionMark eq false and ЮрФизЛицо eq \'' . $type . '\'';
            $crawler = $this->client->request('GET', $url);
            $data = json_decode($this->client->getResponse()->getContent(), true);

        }
        if ($iin == '9201255300884') {
            Log::debug($data);
        }
        return $data;
    }

    // Создание контрагента в 1С
    public function create_contragent($id = 22998)
    {
        $user = User::find($id);
        if ($user == null) {
            $data['result_code'] = 301;
            $data['result_message'] = 'Клиент не найден';
            return $data;
        }

        // Перед созданием контрагента - надо проверить на наличие
        $cont = $this->get_contragent_iin($user->iin, $user->usertype->buh_param);

        Log::debug(count($cont['value']));
        // Если контрагента в 1С нет  можно создавать
        if (count($cont['value']) == 0) {
            $url = $this->main_url . 'Catalog_Контрагенты?$format=json';


            $param = [
                'Description' => $this->mb_trim($user->fio),
                'НаименованиеПолное' => $this->mb_trim($user->fio),
                "ЮрФизЛицо" => $user->usertype->buh_param,
                "ИдентификационныйКодЛичности" => $user->iin,
                "Parent_Key" => $user->parentkeybuh, // в какую папку добавлять контрагента
            ];
            $crawler = $this->client->request('POST', $url, array(), array(),
                array('HTTP_CONTENT_TYPE' => 'application/json'), json_encode($param));

            $data = json_decode($this->client->getResponse()->getContent(), true);
            Log::debug($data);
            if (isset($data['Ref_Key'])) {
                $user->guid = $data['Ref_Key'];
                $user->code = $data['Code'];
                $user->catalog_guid = $data['Parent_Key'];
                // Надо сохранить guid без вызова событий у модели
                $user->saveQuietly();

                // После сохранения guid надо отправить контактную информацию
                $contact = $this->set_contact_contragent($user->id);
                $data['contact_result_code'] = $contact['result_code'];
                $data['contact_result_message'] = $contact['result_message'];
                $data['result_code'] = 0;
                $data['result_message'] = 'Клиент добавлен';
            } else {
                $data['result_message'] = 'Что-то пошло не так...';
                $data['result_code'] = 300;
            }
            return $data;
        } // Если контрагент есть в 1с - значит тут надо обновить данные
        else {
            $buh = $cont['value'][0];
            $user->guid = $buh['Ref_Key'];
            $user->code = $buh['Code'];
            $user->catalog_guid = $buh['Parent_Key'];
            // Надо сохранить guid без вызова событий у модели
            $user->saveQuietly();

            //После присвоения данных guid надо присвоить правильное наименование в 1с.

            $job = BuhJob::create([
                'comment' => 'Обновление клиента - ' . $user->fio,
                'event' => 'update',
            ]);

            $user->buhjobs()->save($job);

            $data['result_message'] = 'Клиент уже существует в 1С';
            $data['result_code'] = 303;
            return $data;
        }

    }



    // Обновление контрагента в 1С
    public function update_contragent($id = 22998)
    {
        $user = User::find($id);
        if ($user == null) {
            $data['result_code'] = 301;
            $data['result_message'] = 'Клиент не найден';
            return $data;
        }

        // Проверяем guid на наличие
        if ($user->guid != null) {
            $guid = $user->guid;
            $url = $this->main_url . 'Catalog_Контрагенты(guid\'' . $guid . '\')?$format=json';
            $param = [
                'Description' => $this->mb_trim($user->fio),
                'НаименованиеПолное' => $this->mb_trim($user->fio),
                "ЮрФизЛицо" => $user->usertype->buh_param,
                "ИдентификационныйКодЛичности" => $user->iin,
                "Parent_Key" => $user->parentkeybuh, // в какую папку добавлять контрагента
            ];
            $crawler = $this->client->request('PATCH', $url, array(), array(),
                array('HTTP_CONTENT_TYPE' => 'application/json'), json_encode($param));

            $data = json_decode($this->client->getResponse()->getContent(), true);
            if (isset($data['Ref_Key'])) {
                Log::debug('Update contact ' . $user->fio);

                // После обновления контрагента надо обновить контактную информацию
                $contact = $this->update_contact_contragent($user->id);
                $data['contact_result_code'] = $contact['result_code'];
                $data['contact_result_message'] = $contact['result_message'];
                $data['result_code'] = 0;
                $data['result_message'] = 'Клиент добавлен';
            } else {
                $data['result_message'] = 'Что-то пошло не так...';
                $data['result_code'] = 300;
            }
            return $data;
        } else {
            $data['result_message'] = 'Не увидели guid 1С';
            $data['result_code'] = 303;
            return $data;
        }

    }

    // Добавление контактной информации в 1С
    public function set_contact_contragent($id = 2298)
    {
        $user = User::find($id);
        if ($user == null) {
            $data['result_code'] = 301;
            $data['result_message'] = 'Клиент не найден';
            return $data;
        }

        // Перед добавлением контактной информации контрагента - надо проверить на наличие контрагента


        // Если контрагент есть в 1С - можно действовать
        if ($user->guid != null) {
            $url = $this->main_url . 'InformationRegister_КонтактнаяИнформация?$format=json';
            $param = [
                "Объект" => $user->guid,
                "Объект_Type" => "StandardODATA.Catalog_Контрагенты",
                "Тип" => "Адрес",
                "Вид" => "b910ecef-9cc8-44e8-b0df-bc705acb83ed",
                "Вид_Type" => "UnavailableEntities.UnavailableEntity_fd5dfe3b-4056-480c-b900-8cfc535e8d95",
                "Представление" => $user->fulladdress,
                "ЗначениеПоУмолчанию" => true,
            ];
            $crawler = $this->client->request('POST', $url, array(), array(), array('HTTP_CONTENT_TYPE' => 'application/json'), json_encode($param));
            $data = json_decode($this->client->getResponse()->getContent(), true);
            if (isset($data['Ref_Key'])) {
                $data['result_code'] = 0;
                $data['result_message'] = 'Контактная информация добавлена';
                return $data;
            } else {
                $data['result_code'] = 300;
                $data['result_message'] = 'Произошла ошибка';
                return $data;
            }
        }
        $data['result_code'] = 304;
        $data['result_message'] = 'У клиента нет guid';
        return $data;
    }

    // Получение контактной информации с 1С
    public function get_contact_contragent($id = 2298)
    {
        $user = User::find($id);
        $data = null;
        if ($user == null) {
            $data['result_code'] = 301;
            $data['result_message'] = 'Клиент не найден';
            return $data;
        }

        // Если контрагент есть в 1С - можно действовать
        if ($user->guid != null) {

            $param = '(Объект=guid\'' . $user->guid . '\', Вид=\'b910ecef-9cc8-44e8-b0df-bc705acb83ed\', Вид_Type=\'UnavailableEntities.UnavailableEntity_fd5dfe3b-4056-480c-b900-8cfc535e8d95\',Тип=\'Адрес\',Объект_Type=\'StandardODATA.Catalog_Контрагенты\')';

            $url = $this->main_url . 'InformationRegister_КонтактнаяИнформация' . $param . '?$format=json';

            $crawler = $this->client->request('GET', $url);
            $data = json_decode($this->client->getResponse()->getContent(), true);

            return $data;
        }

        return $data;
    }

    // Обновление контактной информации в 1С
    public function update_contact_contragent($id = 2298)
    {
        $user = User::find($id);
        if ($user == null) {
            $data['result_code'] = 301;
            $data['result_message'] = 'Клиент не найден';
            return $data;
        }

        // Перед добавлением контактной информации контрагента - надо проверить на наличие контрагента


        // Если контрагент есть в 1С - можно действовать
        if ($user->guid != null) {
            $param = '(Объект=guid\'' . $user->guid . '\', Вид=\'b910ecef-9cc8-44e8-b0df-bc705acb83ed\', Вид_Type=\'UnavailableEntities.UnavailableEntity_fd5dfe3b-4056-480c-b900-8cfc535e8d95\',Тип=\'Адрес\',Объект_Type=\'StandardODATA.Catalog_Контрагенты\')';

            $url = $this->main_url . 'InformationRegister_КонтактнаяИнформация' . $param . '?$format=json';
            $param = [
                "Представление" => $user->fulladdress,
                "ЗначениеПоУмолчанию" => true,
            ];
            $crawler = $this->client->request('PATCH', $url, array(), array(), array('HTTP_CONTENT_TYPE' => 'application/json'), json_encode($param));
            $data = json_decode($this->client->getResponse()->getContent(), true);
            if (isset($data['Объект'])) {
                $data['result_code'] = 0;
                $data['result_message'] = 'Контактная информация обновлена';
                return $data;
            } else {
                $data['result_code'] = 300;
                $data['result_message'] = 'Произошла ошибка';
                return $data;
            }
        }
        $data['result_code'] = 304;
        $data['result_message'] = 'У клиента нет guid';
        return $data;
    }

    // Создание договора в 1С
    public function create_dogovor_contragent($dogovor_id = 2298)
    {
        $contract = Contract::find($dogovor_id);
        if ($contract == null) {
            $data['result_code'] = 301;
            $data['result_message'] = 'Договор не найден';
            return $data;
        }

        $user = $contract->user;
        if ($user == null) {
            $data['result_code'] = 301;
            $data['result_message'] = 'Клиент не найден';
            return $data;
        }
        // Перед попыткой надо проверить наличие guid клиента и договора

        if ($contract->guid != null) {
            $data['result_message'] = 'Договор уже существует в 1С';
            $data['result_code'] = 303;
            return $data;
        }

        if ($user->guid == null) {
            Log::debug($user);
            $data['result_code'] = 304;
            $data['result_message'] = 'У клиента нет guid';
            return $data;
        }

        Log::debug('Договор № ' . $contract->num_doc);
        Log::debug(' от ' . $contract->contract_at . ' г, ');
        Log::debug($user->fulladdress);


        $url = $this->main_url . 'Catalog_ДоговорыКонтрагентов?$format=json';

        $param = [
            "Owner_Key" => $user->guid,
            'Description' => 'Договор № ' . $contract->num_doc . ' от ' . $contract->contract_at->format('d.m.Y') . ' г, ' . $contract->Fulladdress,
            "ВалютаВзаиморасчетов_Key" => "bf2d1331-d19a-11e1-9fab-d02788aff99e",
            "ВедениеВзаиморасчетов" => "ПоДоговоруВЦелом",
            "Комментарий" => "",
            "Организация_Key" => "4a527199-7e25-434c-885b-c33e17869a96",
            "ТипЦен_Key" => "00000000-0000-0000-0000-000000000000",
            "ВидДоговора" => "Прочее",
            "УчетАгентскогоНДС" => false,
            "НомерДоговора" => $contract->num_doc,
            "ДатаДоговора" => $contract->contract_at->format('Y-m-d\T00:00:00'),
            "ДатаНачалаДействияДоговора" => "0001-01-01T00:00:00",
            "ДатаОкончанияДействияДоговора" => "0001-01-01T00:00:00",
            "УстановленСрокОплаты" => false,
            "СрокОплаты" => "0",
            "ДоговорСовместнойДеятельности" => false,
            "УсловияОплаты" => "",
            "УсловияПоставки" => "",
            "УчастникСРП" => false,
            "ПоверенныйОператор_Key" => "00000000-0000-0000-0000-000000000000",
            "СпособВыпискиАктовВыполненныхРабот" => "",
            "УчастникиСовместнойДеятельности" => [],
            "ДополнительныеРеквизиты" => [],
            "Predefined" => false,
            "PredefinedDataName" => "",
        ];
        $crawler = $this->client->request('POST', $url, array(), array(),
            array('HTTP_CONTENT_TYPE' => 'application/json'), json_encode($param));

        $data = json_decode($this->client->getResponse()->getContent(), true);
        Log::debug($data);
        if (isset($data['Ref_Key'])) {
            $contract->guid = $data['Ref_Key'];
            $contract->code = $data['Code'];

            // Надо сохранить guid без вызова событий у модели
            $contract->saveQuietly();
            $data['result_code'] = 0;
            $data['result_message'] = 'Договор создан';
        } else {
            $data['result_message'] = 'Что-то пошло не так...';
            $data['result_code'] = 300;
        }
        return $data;

    }

    // Обновление договора в 1С
    public function update_dogovor_contragent($dogovor_id = 2298)
    {

        $contract = Contract::find($dogovor_id);
        if ($contract == null) {
            $data['result_code'] = 301;
            $data['result_message'] = 'Договор не найден';
            return $data;
        }

        $user = $contract->user;
        if ($user == null) {
            $data['result_code'] = 301;
            $data['result_message'] = 'Клиент не найден';
            return $data;
        }
        // Перед попыткой надо проверить наличие guid клиента и договора

        if ($contract->guid == null) {
            $data['result_message'] = 'Договор не существует в 1С';
            $data['result_code'] = 303;
            return $data;
        }

        if ($user->guid == null) {
            Log::debug($user);
            $data['result_code'] = 304;
            $data['result_message'] = 'У клиента нет guid';
            return $data;
        }

//        Log::debug('Договор № ' . $contract->num_doc);
//        Log::debug(' от ' . $contract->contract_at . ' г, ');
//        Log::debug($user->fulladdress);


        $url = $this->main_url . 'Catalog_ДоговорыКонтрагентов(guid\'' . $contract->guid . '\')?$format=json';

        $param = [
            "Owner_Key" => $user->guid,
            'Description' => 'Договор № ' . $contract->num_doc . ' от ' . $contract->contract_at->format('d.m.Y') . ' г, ' . $contract->Fulladdress,
            "ВалютаВзаиморасчетов_Key" => "bf2d1331-d19a-11e1-9fab-d02788aff99e",
            "ВедениеВзаиморасчетов" => "ПоДоговоруВЦелом",
            "Комментарий" => "",
            "Организация_Key" => "4a527199-7e25-434c-885b-c33e17869a96",
            "ТипЦен_Key" => "00000000-0000-0000-0000-000000000000",
            "ВидДоговора" => "Прочее",
            "УчетАгентскогоНДС" => false,
            "НомерДоговора" => $contract->num_doc,
            "ДатаДоговора" => $contract->contract_at->format('Y-m-d\T00:00:00'),
            "ДатаНачалаДействияДоговора" => "0001-01-01T00:00:00",
            "ДатаОкончанияДействияДоговора" => "0001-01-01T00:00:00",
            "УстановленСрокОплаты" => false,
            "СрокОплаты" => "0",
            "ДоговорСовместнойДеятельности" => false,
            "УсловияОплаты" => "",
            "УсловияПоставки" => "",
            "УчастникСРП" => false,
            "ПоверенныйОператор_Key" => "00000000-0000-0000-0000-000000000000",
            "СпособВыпискиАктовВыполненныхРабот" => "",
            "УчастникиСовместнойДеятельности" => [],
            "ДополнительныеРеквизиты" => [],
            "Predefined" => false,
            "PredefinedDataName" => "",
        ];
        $crawler = $this->client->request('PATCH', $url, array(), array(),
            array('HTTP_CONTENT_TYPE' => 'application/json'), json_encode($param));

        Log::debug($url);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        Log::debug($data);
        if (isset($data['Ref_Key'])) {


            // Надо сохранить guid без вызова событий у модели

            $data['result_code'] = 0;
            $data['result_message'] = 'Договор обновлен';
        } else {
            $data['result_message'] = 'Что-то пошло не так...';
            $data['result_code'] = 300;
        }
        return $data;

    }

    //Временно
    public function upload_dogovors()
    {
        $users = User::where('id','>',24063)->get();
        foreach ($users as $user)
        {
            $this->get_dogovor_contragent($user->id);
        }
        return 1;
    }

    public function check_users()
    {
        $users = User::where('checked',0)->with('contracts')->whereHas('contracts',function($q){
            $q->where('online',1);
        })->get();
        $i = 0;
        foreach ($users as $user)
        {
            $url = $this->main_url . 'Catalog_Контрагенты(guid\'' . $user->guid . '\')?$format=json&$filter=DeletionMark eq false';
            $crawler = $this->client->request('GET', $url);
            $data = json_decode($this->client->getResponse()->getContent(), true);
            if(isset($data['Ref_Key']))
            {
                $user->code = $data['Code'];

                $user->checked = 1;
                $user->saveQuietly();
                $i++;

            }
            else
            {
                dd($data);
            }

        }
        return $i;
    }

    // Проверка на существования договора по его guid
    public function check_contract()
    {
        $contracts = Contract::where('online',1)->where('checked',0)->get();
        foreach ($contracts as $contract)
        {
            $url = $this->main_url . 'Catalog_ДоговорыКонтрагентов(guid\'' . $contract->guid . '\')?$format=json';
            $crawler = $this->client->request('GET', $url);
            $data = json_decode($this->client->getResponse()->getContent(), true);

            if(isset($data['Ref_Key']))
            {
                $contract->checked = 1;
                $contract->saveQuietly();

            }
            else
            {
                //dd($contract);
            }
        }


    }

    // Получение договоров из 1С
    // Если id договора и id контрагента не переданы - будем получать все договора из 1С и синхронихировать их с базой
    public function get_dogovor_contragent($id_contragent = null, $id_dogovor = null)
    {
        $data = null;


        // Если id договора и id контрагента не переданы - будем получать все договора из 1С и синхронихировать их с базой
        if ($id_dogovor == null && $id_contragent == null) {
            $url = $this->main_url . 'Catalog_ДоговорыКонтрагентов?$format=json';
            $crawler = $this->client->request('GET', $url);
            $data = json_decode($this->client->getResponse()->getContent(), true);
            Log::debug('get_dogovor_contragent - ALL');
            $total = count($data['value']);
        }

        // Если id договора не передан. но есть id контрагента - будем получать все договора этого контрагента из 1С
        // и синхронихировать их с базой
        if ($id_dogovor == null && $id_contragent != null) {
            $user = User::find($id_contragent);
            if ($user == null) {
                return 'User not found';
            }
            if ($user->guid == null) {
                return 'User not have guid';
            }
            $url = $this->main_url . 'Catalog_ДоговорыКонтрагентов?$format=json&$filter=Owner_Key eq guid\'' . $user->guid . '\'';
            $crawler = $this->client->request('GET', $url);
            $data = json_decode($this->client->getResponse()->getContent(), true);
            $total = count($data['value']);
        }

        $i = 0;
        $skip = 0;
        foreach ($data['value'] as $item) {


            $user = User::where('guid', $item['Owner_Key'])->first();
            if ($user == null) {
                Log::debug('Not found user with guid - ' . $item['Owner_Key']);
                Log::debug($item['Description']);
                $skip++;
                continue;
            }
            $i++;
            $user_id = $user->id;
            $guid = $item['Ref_Key'];
            $code = $item['Code'];
            $deletion_mark = $item['DeletionMark'];
            if ($item['НомерДоговора'] == '') {
                $num_doc = $this->mb_trim($this->GetBetween('№', 'от', $item['Description']));
                if (strlen($num_doc) < 1) {
                    $num_doc = $item['Description'];
                }
            } else {
                $num_doc = $item['НомерДоговора'];
            }


            if ($item['ДатаДоговора'] == '' || $item['ДатаДоговора'] == '0001-01-01T00:00:00') {
                $contract_at = trim($this->GetBetween('от', 'г', $item['Description']));

            } else {
                $contract_at = $item['ДатаДоговора'];
            }

            if (Carbon::hasFormat($contract_at, 'd.m.Y')) {
                $contract_at_carb = Carbon::createFromFormat('d.m.Y', $contract_at)->startOfDay();
            } elseif (Carbon::hasFormat($contract_at, 'Y-m-d\TH:i:s')) {
                $contract_at_carb = Carbon::createFromFormat('Y-m-d\TH:i:s', $contract_at)->startOfDay();
            } else {
                $contract_at_carb = Carbon::now()->startOfYear();
            }


            $address = mb_strstr($item['Description'], 'г, ', true);
            if (!$address) {
                $address = mb_strstr($item['Description'], 'г,', true);
            }
            if (!$address) {
                $address = 'Not parse';
            }


            Log::debug($i);

            Log::debug($guid);

            $contract = Contract::where('guid', $guid)->first();
            if ($contract == null) {
                Log::debug('Create');
                $contract = new Contract();
                $contract->user_id = $user_id;
                $contract->guid = $guid;
                $contract->code = $code;
                $contract->deletionmark = $deletion_mark;
                $contract->online = !$deletion_mark;
                $contract->num_doc = $num_doc;
                $contract->address = $address;
                $contract->contract_at = $contract_at_carb;
                $contract->description = $item['Description'];
                $contract->saveQuietly();
            } else {
                Log::debug('Update');
                $contract->user_id = $user_id;
                $contract->guid = $guid;
                $contract->code = $code;
                $contract->deletionmark = $deletion_mark;
//                $contract->online = !$deletion_mark;
                $contract->num_doc = $num_doc;
                $contract->address = $address;
                $contract->contract_at = $contract_at_carb;
                $contract->description = $item['Description'];
                $contract->saveQuietly();
            }
        }

        Log::debug('Total - ' . $total);
        Log::debug('Skeep - ' . $skip);
        Log::debug('Complete - ' . $i);
        return 1;
    }

    //Получение номенклатуры из справочника 1С
    public function get_nomenclatures()
    {
        $url = $this->main_url . 'Catalog_Номенклатура?$format=json&$filter=IsFolder eq false and DeletionMark eq false';
        $crawler = $this->client->request('GET', $url);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        if (count($data['value'])) {
            foreach ($data['value'] as $item) {
                if (isset($item['Ref_Key'])) {

                    Nomenclature::updateorcreate([
                        'guid' => $item['Ref_Key']
                    ], [
                        'code' => $item['Code'],
                        'name' => $item['Description'],
                        'usluga' => $item['Услуга'],
                    ]);

                }
            }
        }
        return 1;
    }

    //Получение счетов доходы Субконто из справочника 1С
    public function get_subconto()
    {
        $url = $this->main_url . 'Catalog_Доходы?$format=json&$filter=IsFolder eq false and DeletionMark eq false';
        $crawler = $this->client->request('GET', $url);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        if (count($data['value'])) {
            foreach ($data['value'] as $item) {
                if (isset($item['Ref_Key'])) {
                    $subconto = new IncomeSubconto();
                    $subconto->guid = $item['Ref_Key'];
                    $subconto->code = $item['Code'];
                    $subconto->title = $item['Description'];
                    $subconto->save();
                }
            }
        }
        return 1;
    }


    // Создание документа реализации в 1С
    public function create_sale_of_goods($id = null)
    {
        Log::debug('create_sale_of_goods - '. $id);
        if ($id == null) {
            return $id;
        }

        $action = ContractAction::find($id);

        // Если операцию не нашли или у операции есть guid - ничего делать не надо.
        if ($action == null || $action->guid != null) {
            $data['result_message'] = 'Операцию не нашли или документ уже имеет guid';
            $data['result_code'] = 300;
            return $data;
        }

        $url = $this->main_url . 'Document_РеализацияТоваровУслуг?$format=json';

        if ($action->ContractActionUslugs()->count() > 0) {

            $uslugs = [];
            $i = 0;
            foreach ($action->ContractActionUslugs as $item)
            {
                $i++;
                $uslugs[] =
                    [
                        "LineNumber" => $i,
                        "Содержание" => $item->nomeclature->name,
                        "Количество" => 1,
                        "Цена" => $item->summ,
                        "Сумма" => $item->summ,
                        "СтавкаНДС_Key" => "bf2d133f-d19a-11e1-9fab-d02788aff99e",
                        "СуммаНДС" => round(($item->summ / 1.12) * 0.12, 2),
                        "Номенклатура_Key" => $item->nomeclature->guid,
                        "СчетУчетаНДСПоРеализации_Key" => "05c0dc58-5cfc-4f6f-a37b-66ee68a56966",
                        "СчетДоходовБУ_Key" => "ac7aab45-1127-4549-8d0c-659cedbab312",
                        "СубконтоДоходовБУ1" => $item->nomeclature->subconto->guid,
                        "СубконтоДоходовБУ1_Type" => "StandardODATA.Catalog_Доходы",
                        "СубконтоДоходовБУ2" => "bf2d1344-d19a-11e1-9fab-d02788aff99e",
                        "СубконтоДоходовБУ2_Type" => "UnavailableEntities.UnavailableEntity_2585982c-8c30-4b64-84ec-3139abd10f19",
                        "СубконтоДоходовБУ3" => "",
                        "СубконтоДоходовБУ3_Type" => "StandardODATA.Undefined",
                        "НДСВидОперацииРеализации_Key" => "0fbefb02-5c4e-4169-9fff-c159a570092b",
                        "СчетДоходовНУ_Key" => "5501ffb4-1f90-46eb-ba21-cf05a2763550",
                        "СубконтоДоходовНУ1" => $item->nomeclature->subconto->guid,
                        "СубконтоДоходовНУ1_Type" => "StandardODATA.Catalog_Доходы",
                        "СубконтоДоходовНУ2" => $item->nomeclature->guid,
                        "СубконтоДоходовНУ2_Type" => "StandardODATA.Catalog_Номенклатура",
                        "СубконтоДоходовНУ3" => "",
                        "СубконтоДоходовНУ3_Type" => "StandardODATA.Undefined"
                    ];
            }

            $param = [
                "Date" => $action->action_at->format('Y-m-d\T00:00:00'),
                "БанковскийСчетОрганизации_Key" => "a36e4b8e-d19b-11e1-9fab-d02788aff99e",
                "ВалютаДокумента_Key" => "bf2d1331-d19a-11e1-9fab-d02788aff99e",
                "ВидОперации" => "ПродажаКомиссия",
                "ВидУчетаНУ_Key" => "6f77648a-3e92-419b-9180-a24cb053d3bd",
                "Грузополучатель_Key" => "00000000-0000-0000-0000-000000000000",
                "ДоговорКонтрагента_Key" => $action->contract->guid,
                "ДокументОснование" => "",
                "ДокументОснование_Type" => "StandardODATA.Undefined",
                "Комментарий" => "",
                "Контрагент_Key" => $action->user->guid,
                "КратностьВзаиморасчетов" => "1",
                "КурсВзаиморасчетов" => 1,
                "Организация_Key" => "4a527199-7e25-434c-885b-c33e17869a96",
                "Ответственный_Key" => "bf2d1330-d19a-11e1-9fab-d02788aff99e",
                "РучнаяКорректировка" => false,
                "Сделка" => "",
                "Сделка_Type" => "StandardODATA.Undefined",
                "Склад_Key" => "bf2d133b-d19a-11e1-9fab-d02788aff99e",
                "СуммаВключаетАкциз" => false,
                "СуммаВключаетНДС" => true,
                "СуммаДокумента" => $action->summ,
                "СчетУчетаРасчетовПоАвансам_Key" => "00000000-0000-0000-0000-000000000000",
                "СчетУчетаРасчетовСКонтрагентом_Key" => "fb7e7924-33e5-4d5b-b3a6-24262a02d563",
                "ТипЦен_Key" => "bf2d133e-d19a-11e1-9fab-d02788aff99e",
                "УчитыватьАкциз" => false,
                "УчитыватьНДС" => true,
                "УчитыватьКПН" => true,
                "СтруктурноеПодразделение_Key" => "00000000-0000-0000-0000-000000000000",
                "ДатаНачалаОтчетногоПериода" => "0001-01-01T00:00:00",
                "ДатаОкончанияОтчетногоПериода" => "0001-01-01T00:00:00",
                "ПереченьДокументации" => "",
                "ДоверенностьВыдана" => "",
                "ДоверенностьДата" => "0001-01-01T00:00:00",
                "ДоверенностьНомер" => "",
                "НомерДокументаГЗ" => "",
                "ДатаДокументаГЗ" => "0001-01-01T00:00:00",
                "ДатаПодписанияГЗ" => "2021-03-31T15:13:15",
                "СпособВыпискиАктовВыполненныхРабот" => "ВБумажномВиде",
                "Услуги" => $uslugs
            ];


        } else {

            $param = [
                "Date" => $action->action_at->format('Y-m-d\T00:00:00'),
                "БанковскийСчетОрганизации_Key" => "a36e4b8e-d19b-11e1-9fab-d02788aff99e",
                "ВалютаДокумента_Key" => "bf2d1331-d19a-11e1-9fab-d02788aff99e",
                "ВидОперации" => "ПродажаКомиссия",
                "ВидУчетаНУ_Key" => "6f77648a-3e92-419b-9180-a24cb053d3bd",
                "Грузополучатель_Key" => "00000000-0000-0000-0000-000000000000",
                "ДоговорКонтрагента_Key" => $action->contract->guid,
                "ДокументОснование" => "",
                "ДокументОснование_Type" => "StandardODATA.Undefined",
                "Комментарий" => "",
                "Контрагент_Key" => $action->user->guid,
                "КратностьВзаиморасчетов" => "1",
                "КурсВзаиморасчетов" => 1,
                "Организация_Key" => "4a527199-7e25-434c-885b-c33e17869a96",
                "Ответственный_Key" => "bf2d1330-d19a-11e1-9fab-d02788aff99e",
                "РучнаяКорректировка" => false,
                "Сделка" => "",
                "Сделка_Type" => "StandardODATA.Undefined",
                "Склад_Key" => "bf2d133b-d19a-11e1-9fab-d02788aff99e",
                "СуммаВключаетАкциз" => false,
                "СуммаВключаетНДС" => true,
                "СуммаДокумента" => $action->summ,
                "СчетУчетаРасчетовПоАвансам_Key" => "00000000-0000-0000-0000-000000000000",
                "СчетУчетаРасчетовСКонтрагентом_Key" => "fb7e7924-33e5-4d5b-b3a6-24262a02d563",
                "ТипЦен_Key" => "bf2d133e-d19a-11e1-9fab-d02788aff99e",
                "УчитыватьАкциз" => false,
                "УчитыватьНДС" => true,
                "УчитыватьКПН" => true,
                "СтруктурноеПодразделение_Key" => "00000000-0000-0000-0000-000000000000",
                "ДатаНачалаОтчетногоПериода" => "0001-01-01T00:00:00",
                "ДатаОкончанияОтчетногоПериода" => "0001-01-01T00:00:00",
                "ПереченьДокументации" => "",
                "ДоверенностьВыдана" => "",
                "ДоверенностьДата" => "0001-01-01T00:00:00",
                "ДоверенностьНомер" => "",
                "НомерДокументаГЗ" => "",
                "ДатаДокументаГЗ" => "0001-01-01T00:00:00",
                "ДатаПодписанияГЗ" => "2021-03-31T15:13:15",
                "СпособВыпискиАктовВыполненныхРабот" => "ВБумажномВиде",
                "Услуги" => [
                    [
                        "LineNumber" => "1",
                        "Содержание" => $action->nomen->name,
                        "Количество" => 1,
                        "Цена" => $action->summ,
                        "Сумма" => $action->summ,
                        "СтавкаНДС_Key" => "bf2d133f-d19a-11e1-9fab-d02788aff99e",
                        "СуммаНДС" => round(($action->summ / 1.12) * 0.12, 2),
                        "Номенклатура_Key" => $action->nomen->guid,
                        "СчетУчетаНДСПоРеализации_Key" => "05c0dc58-5cfc-4f6f-a37b-66ee68a56966",
                        "СчетДоходовБУ_Key" => "ac7aab45-1127-4549-8d0c-659cedbab312",
                        "СубконтоДоходовБУ1" => "2352a67f-7a60-11ea-8cf8-8c89a5107836",
                        "СубконтоДоходовБУ1_Type" => "StandardODATA.Catalog_Доходы",
                        "СубконтоДоходовБУ2" => "bf2d1344-d19a-11e1-9fab-d02788aff99e",
                        "СубконтоДоходовБУ2_Type" => "UnavailableEntities.UnavailableEntity_2585982c-8c30-4b64-84ec-3139abd10f19",
                        "СубконтоДоходовБУ3" => "",
                        "СубконтоДоходовБУ3_Type" => "StandardODATA.Undefined",
                        "НДСВидОперацииРеализации_Key" => "0fbefb02-5c4e-4169-9fff-c159a570092b",
                        "СчетДоходовНУ_Key" => "5501ffb4-1f90-46eb-ba21-cf05a2763550",
                        "СубконтоДоходовНУ1" => "2352a67f-7a60-11ea-8cf8-8c89a5107836",
                        "СубконтоДоходовНУ1_Type" => "StandardODATA.Catalog_Доходы",
                        "СубконтоДоходовНУ2" => "5cd5d557-2f8a-11ea-b511-1078d21f324b",
                        "СубконтоДоходовНУ2_Type" => "StandardODATA.Catalog_Номенклатура",
                        "СубконтоДоходовНУ3" => "",
                        "СубконтоДоходовНУ3_Type" => "StandardODATA.Undefined"
                    ]
                ]
            ];

        }


        $crawler = $this->client->request('POST', $url, array(), array(),
            array('HTTP_CONTENT_TYPE' => 'application/json'), json_encode($param));

        $data = json_decode($this->client->getResponse()->getContent(), true);
        Log::debug($data);
        if (isset($data['Ref_Key'])) {
            $action->guid = $data['Ref_Key'];
            $action->code = $data['Number'];
            $action->posted = $data['Posted'];
            // Надо сохранить guid без вызова событий у модели
            $action->saveQuietly();
            $data['result_code'] = 0;
            $data['result_message'] = 'Реализация создана';

            // Если реализация создана и она не проведена - надо создать задачу на проведение реализации
            if (!$action->posted) {
                $job = BuhJob::create([
                    'comment' => 'Проведение реализации - ' . $action->id,
                    'event' => 'posted',
                ]);
                $action->buhjobs()->save($job);
            }

        } else {
            $data['result_message'] = 'Что-то пошло не так...';
            $data['result_code'] = 300;
        }
        return $data;
    }

    // Обновление документа реализации в 1С
    public function update_sale_of_goods($id = 22998)
    {
        if ($id == null) {
            return $id;
        }

        $action = ContractAction::find($id);

        // Если операцию не нашли или у операции есть guid - ничего делать не надо.
        if ($action == null || $action->guid == null) {
            $data['result_message'] = 'Операцию не нашли или документ без guid';
            $data['result_code'] = 300;
            return $data;
        }

        $url = $this->main_url . 'Document_РеализацияТоваровУслуг(guid\'' . $action->guid . '\')?$format=json';

        if ($action->ContractActionUslugs()->count() > 0) {

            $uslugs = [];
            $i = 0;
            foreach ($action->ContractActionUslugs as $item)
            {
                $i++;
                $uslugs[] =
                    [
                        "LineNumber" => $i,
                        "Содержание" => $item->nomeclature->name,
                        "Количество" => 1,
                        "Цена" => $item->summ,
                        "Сумма" => $item->summ,
                        "СтавкаНДС_Key" => "bf2d133f-d19a-11e1-9fab-d02788aff99e",
                        "СуммаНДС" => round(($item->summ / 1.12) * 0.12, 2),
                        "Номенклатура_Key" => $item->nomeclature->guid,
                        "СчетУчетаНДСПоРеализации_Key" => "05c0dc58-5cfc-4f6f-a37b-66ee68a56966",
                        "СчетДоходовБУ_Key" => "ac7aab45-1127-4549-8d0c-659cedbab312",
                        "СубконтоДоходовБУ1" => $item->nomeclature->subconto->guid,
                        "СубконтоДоходовБУ1_Type" => "StandardODATA.Catalog_Доходы",
                        "СубконтоДоходовБУ2" => "bf2d1344-d19a-11e1-9fab-d02788aff99e",
                        "СубконтоДоходовБУ2_Type" => "UnavailableEntities.UnavailableEntity_2585982c-8c30-4b64-84ec-3139abd10f19",
                        "СубконтоДоходовБУ3" => "",
                        "СубконтоДоходовБУ3_Type" => "StandardODATA.Undefined",
                        "НДСВидОперацииРеализации_Key" => "0fbefb02-5c4e-4169-9fff-c159a570092b",
                        "СчетДоходовНУ_Key" => "5501ffb4-1f90-46eb-ba21-cf05a2763550",
                        "СубконтоДоходовНУ1" => $item->nomeclature->subconto->guid,
                        "СубконтоДоходовНУ1_Type" => "StandardODATA.Catalog_Доходы",
                        "СубконтоДоходовНУ2" => $item->nomeclature->guid,
                        "СубконтоДоходовНУ2_Type" => "StandardODATA.Catalog_Номенклатура",
                        "СубконтоДоходовНУ3" => "",
                        "СубконтоДоходовНУ3_Type" => "StandardODATA.Undefined"
                    ];
            }

            $param = [
                "Date" => $action->action_at->format('Y-m-d\T00:00:00'),
                "БанковскийСчетОрганизации_Key" => "a36e4b8e-d19b-11e1-9fab-d02788aff99e",
                "ВалютаДокумента_Key" => "bf2d1331-d19a-11e1-9fab-d02788aff99e",
                "ВидОперации" => "ПродажаКомиссия",
                "ВидУчетаНУ_Key" => "6f77648a-3e92-419b-9180-a24cb053d3bd",
                "Грузополучатель_Key" => "00000000-0000-0000-0000-000000000000",
                "ДоговорКонтрагента_Key" => $action->contract->guid,
                "ДокументОснование" => "",
                "ДокументОснование_Type" => "StandardODATA.Undefined",
                "Комментарий" => "",
                "Контрагент_Key" => $action->user->guid,
                "КратностьВзаиморасчетов" => "1",
                "КурсВзаиморасчетов" => 1,
                "Организация_Key" => "4a527199-7e25-434c-885b-c33e17869a96",
                "Ответственный_Key" => "bf2d1330-d19a-11e1-9fab-d02788aff99e",
                "РучнаяКорректировка" => false,
                "Сделка" => "",
                "Сделка_Type" => "StandardODATA.Undefined",
                "Склад_Key" => "bf2d133b-d19a-11e1-9fab-d02788aff99e",
                "СуммаВключаетАкциз" => false,
                "СуммаВключаетНДС" => true,
                "СуммаДокумента" => $action->summ,
                "СчетУчетаРасчетовПоАвансам_Key" => "00000000-0000-0000-0000-000000000000",
                "СчетУчетаРасчетовСКонтрагентом_Key" => "fb7e7924-33e5-4d5b-b3a6-24262a02d563",
                "ТипЦен_Key" => "bf2d133e-d19a-11e1-9fab-d02788aff99e",
                "УчитыватьАкциз" => false,
                "УчитыватьНДС" => true,
                "УчитыватьКПН" => true,
                "СтруктурноеПодразделение_Key" => "00000000-0000-0000-0000-000000000000",
                "ДатаНачалаОтчетногоПериода" => "0001-01-01T00:00:00",
                "ДатаОкончанияОтчетногоПериода" => "0001-01-01T00:00:00",
                "ПереченьДокументации" => "",
                "ДоверенностьВыдана" => "",
                "ДоверенностьДата" => "0001-01-01T00:00:00",
                "ДоверенностьНомер" => "",
                "НомерДокументаГЗ" => "",
                "ДатаДокументаГЗ" => "0001-01-01T00:00:00",
                "ДатаПодписанияГЗ" => "2021-03-31T15:13:15",
                "СпособВыпискиАктовВыполненныхРабот" => "ВБумажномВиде",
                "Услуги" => $uslugs
            ];


        } else {

            $param = [
                "Date" => $action->action_at->format('Y-m-d\T00:00:00'),
                "БанковскийСчетОрганизации_Key" => "a36e4b8e-d19b-11e1-9fab-d02788aff99e",
                "ВалютаДокумента_Key" => "bf2d1331-d19a-11e1-9fab-d02788aff99e",
                "ВидОперации" => "ПродажаКомиссия",
                "ВидУчетаНУ_Key" => "6f77648a-3e92-419b-9180-a24cb053d3bd",
                "Грузополучатель_Key" => "00000000-0000-0000-0000-000000000000",
                "ДоговорКонтрагента_Key" => $action->contract->guid,
                "ДокументОснование" => "",
                "ДокументОснование_Type" => "StandardODATA.Undefined",
                "Комментарий" => "",
                "Контрагент_Key" => $action->user->guid,
                "КратностьВзаиморасчетов" => "1",
                "КурсВзаиморасчетов" => 1,
                "Организация_Key" => "4a527199-7e25-434c-885b-c33e17869a96",
                "Ответственный_Key" => "bf2d1330-d19a-11e1-9fab-d02788aff99e",
                "РучнаяКорректировка" => false,
                "Сделка" => "",
                "Сделка_Type" => "StandardODATA.Undefined",
                "Склад_Key" => "bf2d133b-d19a-11e1-9fab-d02788aff99e",
                "СуммаВключаетАкциз" => false,
                "СуммаВключаетНДС" => true,
                "СуммаДокумента" => $action->summ,
                "СчетУчетаРасчетовПоАвансам_Key" => "00000000-0000-0000-0000-000000000000",
                "СчетУчетаРасчетовСКонтрагентом_Key" => "fb7e7924-33e5-4d5b-b3a6-24262a02d563",
                "ТипЦен_Key" => "bf2d133e-d19a-11e1-9fab-d02788aff99e",
                "УчитыватьАкциз" => false,
                "УчитыватьНДС" => true,
                "УчитыватьКПН" => true,
                "СтруктурноеПодразделение_Key" => "00000000-0000-0000-0000-000000000000",
                "ДатаНачалаОтчетногоПериода" => "0001-01-01T00:00:00",
                "ДатаОкончанияОтчетногоПериода" => "0001-01-01T00:00:00",
                "ПереченьДокументации" => "",
                "ДоверенностьВыдана" => "",
                "ДоверенностьДата" => "0001-01-01T00:00:00",
                "ДоверенностьНомер" => "",
                "НомерДокументаГЗ" => "",
                "ДатаДокументаГЗ" => "0001-01-01T00:00:00",
                "ДатаПодписанияГЗ" => "2021-03-31T15:13:15",
                "СпособВыпискиАктовВыполненныхРабот" => "ВБумажномВиде",
                "Услуги" => [
                    [
                        "LineNumber" => "1",
                        "Содержание" => $action->nomen->name,
                        "Количество" => 1,
                        "Цена" => $action->summ,
                        "Сумма" => $action->summ,
                        "СтавкаНДС_Key" => "bf2d133f-d19a-11e1-9fab-d02788aff99e",
                        "СуммаНДС" => round(($action->summ / 1.12) * 0.12, 2),
                        "Номенклатура_Key" => $action->nomen->guid,
                        "СчетУчетаНДСПоРеализации_Key" => "05c0dc58-5cfc-4f6f-a37b-66ee68a56966",
                        "СчетДоходовБУ_Key" => "ac7aab45-1127-4549-8d0c-659cedbab312",
                        "СубконтоДоходовБУ1" => "2352a67f-7a60-11ea-8cf8-8c89a5107836",
                        "СубконтоДоходовБУ1_Type" => "StandardODATA.Catalog_Доходы",
                        "СубконтоДоходовБУ2" => "bf2d1344-d19a-11e1-9fab-d02788aff99e",
                        "СубконтоДоходовБУ2_Type" => "UnavailableEntities.UnavailableEntity_2585982c-8c30-4b64-84ec-3139abd10f19",
                        "СубконтоДоходовБУ3" => "",
                        "СубконтоДоходовБУ3_Type" => "StandardODATA.Undefined",
                        "НДСВидОперацииРеализации_Key" => "0fbefb02-5c4e-4169-9fff-c159a570092b",
                        "СчетДоходовНУ_Key" => "5501ffb4-1f90-46eb-ba21-cf05a2763550",
                        "СубконтоДоходовНУ1" => "2352a67f-7a60-11ea-8cf8-8c89a5107836",
                        "СубконтоДоходовНУ1_Type" => "StandardODATA.Catalog_Доходы",
                        "СубконтоДоходовНУ2" => "5cd5d557-2f8a-11ea-b511-1078d21f324b",
                        "СубконтоДоходовНУ2_Type" => "StandardODATA.Catalog_Номенклатура",
                        "СубконтоДоходовНУ3" => "",
                        "СубконтоДоходовНУ3_Type" => "StandardODATA.Undefined"
                    ]
                ]
            ];

        }
        $crawler = $this->client->request('PATCH', $url, array(), array(),
            array('HTTP_CONTENT_TYPE' => 'application/json'), json_encode($param));


        $data = json_decode($this->client->getResponse()->getContent(), true);
        Log::debug($data);
        if (isset($data['Ref_Key'])) {
            $action->guid = $data['Ref_Key'];
            $action->code = $data['Number'];
            $action->posted = $data['Posted'];
            // Надо сохранить guid без вызова событий у модели
            $action->saveQuietly();
            $data['result_code'] = 0;
            $data['result_message'] = 'Реализация создана';

            // Если реализация создана и она не проведена - надо создать задачу на проведение реализации
            if (!$action->posted) {
                $job = BuhJob::create([
                    'comment' => 'Проведение реализации - ' . $action->id,
                    'event' => 'posted',
                ]);
                $action->buhjobs()->save($job);
            } else {
                $this->posted_action($action->id, true);
            }

        } else {
            $data['result_message'] = 'Что-то пошло не так...';
            $data['result_code'] = 300;
        }
        return $data;

    }

    // Удаление документа реализации в 1С
    public function delete_sale_of_goods($id)
    {
        if ($id == null) {
            return $id;
        }

        $action = ContractAction::withTrashed()->find($id);

//        dd($action);

        // Если операцию не нашли или у операции есть guid - ничего делать не надо.
        if ($action == null || $action->guid == null) {
            $data['result_message'] = 'Операцию не нашли или документ без guid';
            $data['result_code'] = 300;
            return $data;
        }
        $url = $this->main_url . 'Document_РеализацияТоваровУслуг(guid\'' . $action->guid . '\')?$format=json';
        $crawler = $this->client->request('DELETE', $url);
        $data = json_decode($this->client->getResponse()->getContent(), true);

        // Если возврат пустой - значит документ проведен без ошибок
        if (!$data) {

            $action->guid = null;
            $action->posted = false;
            $action->code = null;
            $action->comment = $action->comment . PHP_EOL . 'Удаление документа из 1С - ' . now()->format('d.m.Y h:i:s');
            $action->saveQuietly();


            $data['result_code'] = 0;
            $data['result_message'] = 'Документ удален';
        } else {
            $data['result_message'] = 'Что-то пошло не так...';
            $data['result_code'] = 300;

        }

        return $data;

    }


    // Проведение документа
    public function posted_action($id = null, $ignored = false)
    {
        if ($id == null) {
            return $id;
        }

        $action = ContractAction::find($id);

        // Если операцию не нашли или у операции нет guid или операция уже проведена - ничего делать не надо.
        if ($action == null || $action->guid == null || $action->posted) {
            $data['result_message'] = 'Операцию не нашли или документ без guid или документ уже проведен!';
            $data['result_code'] = 300;
            if (!$ignored) {
                return $data;
            }
        }

        $url = $this->main_url . 'Document_РеализацияТоваровУслуг(guid\'' . $action->guid . '\')/Post()';
        $crawler = $this->client->request('GET', $url);
        $data = json_decode($this->client->getResponse()->getContent(), true);

        // Если возврат пустой - значит документ проведен без ошибок
        if (!$data) {
            $action->posted = 1;
            // Надо сохранить guid без вызова событий у модели
            $action->saveQuietly();
            $data['result_code'] = 0;
            $data['result_message'] = 'Документ проведен';
        } else {
            $data['result_message'] = 'Что-то пошло не так...';
            $data['result_code'] = 300;

        }

        return $data;


    }

    // Удаление пользователя из CRM с удалением всех связей
    public function delete_user($id)
    {
        Phone::where('user_id', $id)->delete();
        Document::where('user_id', $id)->delete();
        Ticket::where('user_id', $id)->delete();
        User::where('id', $id)->delete();
    }

    // Удаление клиентов, которые удалены в 1С
    public function delete_contragent_from_buh()
    {
        Log::debug('Run delete_contragent_from_buh');
        // Получаем контрагентов у которых есть guid
        $users = User::where('guid', '!=', 'NULL')->get();

        Log::debug('Users count - ' . $users->count());
        $i = 0;
        foreach ($users as $user) {
            $cont = $this->get_contragent_guid($user->guid);

            if ($cont['DeletionMark']) {
                Log::debug('Delete user - ' . $user->id);
                $this->delete_user($user->id);
                $i++;
            }
        }
        Log::debug('Deleted users - ' . $i);

        return 1;


    }

    public function GetBetween($var1 = "", $var2 = "", $pool)
    {
        $temp1 = strpos($pool, $var1) + strlen($var1);
        $result = substr($pool, $temp1, strlen($pool));
        $dd = strpos($result, $var2);
        if ($dd == 0) {
            $dd = strlen($result);
        }

        return substr($result, 0, $dd);
    }

    public function mb_trim($str)
    {
        return preg_replace("/^\s+|\s+$/u", "", $str);
    }

}
