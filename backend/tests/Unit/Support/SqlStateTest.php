<?php

// backend/tests/Unit/Support/SqlStateTest.php

use App\Support\SqlState;
use Illuminate\Database\QueryException;

/** A QueryException wrapping a PDOException with the given SQLSTATE in errorInfo, as PDO raises them. */
function usqlException(string $sqlState, string $message = 'driver message'): QueryException
{
    $pdo = new PDOException("SQLSTATE[$sqlState]: $message");
    $pdo->errorInfo = [$sqlState, 7, $message];

    return new QueryException('pgsql', 'select ?', ['x'], $pdo);
}

it('treats the PostgreSQL invalid-input states as the caller\'s mistake', function (string $sqlState) {
    expect(SqlState::isInvalidInput(usqlException($sqlState)))->toBeTrue();
})->with([
    'invalid text representation' => ['22P02'],
    'invalid datetime format' => ['22007'],
    'datetime field overflow' => ['22008'],
    'numeric value out of range' => ['22003'],
]);

it('leaves other errors to be a 500', function (string $sqlState) {
    expect(SqlState::isInvalidInput(usqlException($sqlState)))->toBeFalse();
})->with([
    'unique violation' => ['23505'],
    'foreign key violation' => ['23503'],
    'not null violation' => ['23502'],
    'undefined table' => ['42P01'],
    'deadlock' => ['40P01'],
    'serialization failure' => ['40001'],
    'connection failure' => ['08006'],
]);

it('falls back to the exception code when there is no errorInfo', function () {
    // A previous exception that is not a PDOException carries no errorInfo; its code is used instead.
    $coded = new class('bad input') extends RuntimeException
    {
        public function __construct(string $message)
        {
            parent::__construct($message);
            $this->code = '22P02';
        }
    };
    expect(SqlState::isInvalidInput(new QueryException('pgsql', 'select 1', [], $coded)))->toBeTrue();

    $plain = new QueryException('pgsql', 'select 1', [], new RuntimeException('boom', 0));
    expect($plain->errorInfo)->toBeNull();
    expect(SqlState::isInvalidInput($plain))->toBeFalse();
});

it('reads errorInfo first even when the exception code says otherwise', function () {
    $pdo = new PDOException('SQLSTATE[23505]: duplicate key', 0);
    $pdo->errorInfo = ['23505', 7, 'duplicate key'];
    $e = new QueryException('pgsql', 'insert', [], $pdo);

    expect($e->getCode())->toBe(0);
    expect(SqlState::isInvalidInput($e))->toBeFalse();
});
