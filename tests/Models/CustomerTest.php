<?php

use Statikbe\FilamentVoight\Models\Customer;
use Statikbe\FilamentVoight\Models\Project;

it('can be created via factory', function () {
    $customer = Customer::factory()->create();

    expect($customer)->toBeInstanceOf(Customer::class)
        ->and($customer->name)->toBeString()
        ->and($customer->slug)->toBeString();
});

it('has many projects', function () {
    $customer = Customer::factory()->create();
    Project::factory()->for($customer)->create();

    expect($customer->projects)->toHaveCount(1);
});
