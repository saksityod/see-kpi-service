<?php

namespace App\Http\Controllers\PMTL;

use App\Questionaire;

use Auth;
use DB;
use File;
use Validator;
use Excel;
use Exception;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class QuestionaireController extends Controller
{

	public function __construct()
	{
	   $this->middleware('jwt.auth');
	}

	public function list_type() {
		$items = DB::select("
			SELECT questionaire_type_id, questionaire_type
			FROM questionaire_type
			ORDER BY questionaire_type_id
		");
		return response()->json($items);
	}

	public function auto_name(Request $request) {
		$questionaire_type_id = empty($request->questionaire_type_id) ? "" : "AND questionaire_type_id = '{$request->questionaire_type_id}'";

		$items = DB::select("
			SELECT questionaire_id, questionaire_name
			FROM questionaire
			WHERE questionaire_name LIKE '%{$request->questionaire_name}%'
			".$questionaire_type_id."
			AND is_active = 1
			ORDER BY questionaire_id
		");
		return response()->json($items);
	}

	public function index(Request $request)
	{
		$questionaire_type_id = empty($request->questionaire_type_id) ? "" : "AND q.questionaire_type_id = '{$request->questionaire_type_id}'";
		$questionaire_id = empty($request->questionaire_id) ? "" : "AND q.questionaire_id = '{$request->questionaire_id}'";

		$items =DB::select("
			SELECT q.questionaire_id, 
					qt.questionaire_type,
					q.questionaire_name,
					q.pass_score
			FROM questionaire q
			LEFT OUTER JOIN questionaire_type qt ON qt.questionaire_type_id = q.questionaire_type_id
			WHERE q.is_active = 1
			".$questionaire_type_id."
			".$questionaire_id."
			ORDER BY qt.questionaire_type, q.questionaire_id
		");

		// Get the current page from the url if it's not set default to 1
		empty($request->page) ? $page = 1 : $page = $request->page;

		// Number of items per page
		empty($request->rpp) ? $perPage = 10 : $perPage = $request->rpp;

		$offSet = ($page * $perPage) - $perPage; // Start displaying items from this number

		// Get only the items you need using array_slice (only get 10 items since that's what you need)
		$itemsForCurrentPage = array_slice($items, $offSet, $perPage, false);

		// Return the paginator with only 10 items but with the count of all items and set the it on the correct page
		$result = new LengthAwarePaginator($itemsForCurrentPage, count($items), $perPage, $page);


		return response()->json($result);
	}

	public function show($questionaire_id)
	{

	}

	public function store(Request $request)
	{

	}

	public function update(Request $request)
	{

	}

	public function destroy($questionaire_id)
	{
		try {
			$item = Questionaire::findOrFail($questionaire_id);
		} catch (ModelNotFoundException $e) {
			return response()->json(['status' => 404, 'data' => 'Questionaire not found.']);
		}

		try {
			$item->delete();
		} catch (Exception $e) {
			if ($e->errorInfo[1] == 1451) {
				return response()->json(['status' => 400, 'data' => 'Cannot delete because this Questionaire is in use.']);
			} else {
				return response()->json($e->errorInfo);
			}
		}

		return response()->json(['status' => 200]);

	}
}
