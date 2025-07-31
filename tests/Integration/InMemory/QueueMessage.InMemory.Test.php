<?php

use Tests\TestCase;

uses(TestCase::class);

class queueManangerContainer
{
    public AccountRepository $accountRepository;

    public function __construct()
    {
        $this->accountRepository = new AccountRepository();
    }
}

describe('QueueMessage', function () {
    beforeEach(function () {
        $container = new QueueManangerContainer();

        $account = Account::create();
        $container->accountRepository->save($account);

        $this->sut = new QueueMessage();

        $this->message = [
            "to" => "9999999999",
            "message" => [
                "external_id" => "",
                "type" => "FREE|TEMPLATE",
                "category"=>"",
                "content" => "",
                "template" => [
                    "version" => "",
                    "params" => "",
                ]
            ]
        ];

        $this->input = [
            "account" => $account,
            "messages" => [$this->message, $this->message]
        ];
    });

    it("should queue message's batch", function () {
        $response = $this->sut->execute($this->input);
        expect($response->success)->toBeTrue();
    });

    it("should queue `partially` message's batch", function () {
        $response = $this->sut->execute($this->input);
        $message = clone $this->message;
        $message["message"]["template"]["version"] = 999;
        $this->input->messages[] = $message;

        $queued_partially = $response->success === true && count($response->error_list) !== count($this->input->messages);

        expect($queued_partially)->toBeTrue();
    });
    it("should'nt queue nothing", function () {
        $input = clone $this->input;
        $input->messages = [];

        $message = clone $this->message;
        $message["message"]["template"]["version"] = 999;

        for ($i = 0; $i < 2; $i++) {
            $message["message"]["external_id"] = uniqid();
            $input->messages[] = $message;
        }

        $response = $this->sut->execute($input);
        $queued_nothing = $response->success === false;

        expect($queued_nothing)->toBeTrue();
    });
});
