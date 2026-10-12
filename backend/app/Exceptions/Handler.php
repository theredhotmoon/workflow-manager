<?php

namespace App\Exceptions;

use App\Support\SqlState;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Request;
use Psr\Log\LogLevel;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * A list of exception types with their corresponding custom log levels.
     *
     * @var array<class-string<Throwable>, LogLevel::*>
     */
    protected $levels = [
        //
    ];

    /**
     * A list of the exception types that are not reported.
     *
     * @var array<int, class-string<Throwable>>
     */
    protected $dontReport = [
        //
    ];

    /**
     * A list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     *
     * @return void
     */
    public function register()
    {
        $this->reportable(function (Throwable $e) {
            //
        });

        // Safety net behind request validation (L7): a GET whose filter value PostgreSQL can't read
        // as the column's type (invalid_text_representation 22P02, invalid_datetime_format 22007,
        // datetime_field_overflow 22008, numeric_value_out_of_range 22003) is the caller's mistake,
        // so it answers 422, not 500.
        // Writes are left alone: there a failed statement may be a real bug worth a 500 and a report.
        $this->renderable(function (QueryException $e, Request $request) {
            if ($request->isMethod('GET') && $request->is('api', 'api/*') && SqlState::isInvalidInput($e)) {
                return response()->json(['message' => 'One of the values sent is not valid.'], 422);
            }

            return null;
        });
    }

    /**
     * Every api/* answer is JSON (401, 404, 422, ...), whatever the Accept header: the API has no
     * HTML pages to redirect or render to (F1, F2).
     */
    protected function shouldReturnJson($request, Throwable $e)
    {
        return $request->is('api', 'api/*') || parent::shouldReturnJson($request, $e);
    }
}
