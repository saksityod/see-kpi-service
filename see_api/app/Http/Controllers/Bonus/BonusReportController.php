<?php

namespace App\Http\Controllers\Bonus;

use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;

use App\Http\Requests;
use App\Http\Controllers\Controller;
use App\SystemConfiguration;
use App\AppraisalLevel;
use Auth;
use DB;
use Validator;
use Exception;
use Log;


class BonusReportController extends Controller
{
    public function __construct()
	{
        //$this->middleware('jwt.auth');
    }

    public function index()
    {
        return 1;
    }
}