<?php

use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Policies\JournalEntryPolicy;
use App\Models\User;

it('permite al super usuario borrar un asiento contabilizado', function () {
    $superAdmin = User::factory()->create(['is_super_admin' => true]);
    $posted = new JournalEntry(['status' => 'posted']);

    expect((new JournalEntryPolicy)->delete($superAdmin, $posted))->toBeTrue();
});

it('no permite a un usuario normal borrar un asiento contabilizado, aunque sea administrador', function () {
    $admin = User::factory()->create(['is_super_admin' => false]);
    $posted = new JournalEntry(['status' => 'posted']);

    expect((new JournalEntryPolicy)->delete($admin, $posted))->toBeFalse();
});

it('no permite borrar un asiento anulado salvo al super usuario', function () {
    $regular = User::factory()->create(['is_super_admin' => false]);
    $voided = new JournalEntry(['status' => 'voided']);

    expect((new JournalEntryPolicy)->delete($regular, $voided))->toBeFalse();
});

it('permite borrar un borrador (no contabilizado) a cualquier usuario a nivel de política', function () {
    $regular = User::factory()->create(['is_super_admin' => false]);
    $draft = new JournalEntry(['status' => 'draft']);

    expect((new JournalEntryPolicy)->delete($regular, $draft))->toBeTrue();
});
