<?php

use App\UseCases\Adicionar;
use Tests\TestCase;

uses(TestCase::class);

class queueManangerContainer{
    public AccountRepository $accountRepository;
    public QueueMessage $queueMessageUseCase;

    public function __construct(){
        $this->accountRepository = new AccountRepository();
        $this->queueMessageUseCase = new QueueMessage();
    }
}

describe('QueueMessage', function () {
    beforeEach(function () {
        $container = new QueueManangerContainer();

        $account = Account::create();
        $container->accountRepository->save($account);
        
        $message = [
            "to" => "9999999999",
            "message" => [
                "external_id"=>"",
                "type" => "FREE|TEMPLATE",
                "content" => "",
                "template" => [
                    "version" => "",
                    "params" => "",
                ]
            ]
        ];

        $this->input = [
            "account" => $account,
            "messages" => [$message]
        ];
    });
    it('', function () {

    });
    it('', function () {

    });
    it('', function () {
        
    });
});
