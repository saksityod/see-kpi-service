<?php

namespace App\Http\Controllers\PMTL;
use App\Http\Controllers\PMTL\QuestionaireDataController;

use App\Customer;
use App\CustomerPosition;

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

class CustomerController extends Controller
{
	protected $qdc_service;
	public function __construct(QuestionaireDataController $qdc_service)
	{
	   $this->middleware('jwt.auth');
	   $this->qdc_service = $qdc_service;
	}

	public function list_cus_type() {
		$items = DB::select("
			SELECT DISTINCT customer_type
			FROM customer
		");
		return response()->json($items);
	}

	public function list_industry() {
		$items = DB::select("
			SELECT DISTINCT industry_class
			FROM customer
			ORDER BY industry_class
		");
		return response()->json($items);
	}

	public function auto_cus(Request $request) {
		$customer_type = empty($request->customer_type) ? "" : "AND customer_type = '{$request->customer_type}'";
		$industry_class = empty($request->industry_class) ? "" : "AND industry_class = '{$request->industry_class}'";

		$items = DB::select("
			SELECT customer_id, customer_code, customer_name
			FROM customer
			WHERE customer_name LIKE '%{$request->customer_name}%'
			".$customer_type."
			".$industry_class."
			LIMIT 10
		");
		return response()->json($items);
	}

	public function index(Request $request)
	{
		$customer_type = empty($request->customer_type) ? "" : "AND customer_type = '{$request->customer_type}'";
		$industry_class = empty($request->industry_class) ? "" : "AND industry_class = '{$request->industry_class}'";
		$customer_id = empty($request->customer_id) ? "" : "AND customer_id = '{$request->customer_id}'";

		$items =DB::select("
			SELECT customer_id, 
					customer_code, 
					customer_name, 
					customer_type, 
					industry_class
			FROM customer
			WHERE 1=1
			".$customer_type."
			".$industry_class."
			".$customer_id."
			ORDER BY customer_code
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

	public function import(Request $request)
	{
		set_time_limit(0);
		ini_set('memory_limit', '5012M');
		$errors = array();
		$errors_validator = array();
		// DB::beginTransaction();
		foreach ($request->file() as $f) {
			$items = Excel::load($f, function($reader){})->get();
			foreach ($items as $i) {

				$pc = array($i->tse, $i->vsm, $i->lex);
				$validator = Validator::make($i->toArray(), [
					'customer_code' => 'required|max:20',
					'customer_name' => 'required|max:255',
					'customer_type' => 'required|max:255',
					'industry_class' => 'max:255'
				]);

				if ($validator->fails()) {
					$errors_validator[] = ['customer_code' => $i->customer_code, 'errors' => $validator->errors()];
		            return response()->json(['status' => 400, 'errors' => $errors_validator]);
				} else {
					$cus = DB::select("
						select customer_id
						from customer
						where customer_code = ?
					",array($i->customer_code));
					if (empty($cus)) {
						$new_cus = new Customer;
						$new_cus->customer_code = $this->qdc_service->trim_text($i->customer_code);
						$new_cus->customer_name = $this->qdc_service->trim_text($i->customer_name);
						$new_cus->customer_type = $this->qdc_service->trim_text($i->customer_type);
						$new_cus->industry_class = $this->qdc_service->trim_text($i->industry_class);
						$new_cus->created_by = Auth::id();
						$new_cus->updated_by = Auth::id();
						try {
							$new_cus->save();
						} catch (Exception $e) {
							$errors[] = ['customer_code' => $i->customer_code, 'errors' => ['validate' => substr($e,0,254)]];
						}

						if(!empty($pc)) {
							foreach ($pc as $key => $value) {
								if(!empty($value)) {
									$cp = new CustomerPosition;
									$cp->customer_id = $new_cus->customer_id;
									$cp->position_code = $this->qdc_service->trim_text($value);
									try {
										$cp->save();
									} catch (Exception $e) {
										$errors[] = ['customer_code' => $i->customer_code, 'errors' => ['validate' => substr($e,0,254)]];
									}
								}
							}
						}

					} else {
						$update_cus = Customer::find($cus[0]->customer_id);
						$update_cus->customer_code = $this->qdc_service->trim_text($i->customer_code);
						$update_cus->customer_name = $this->qdc_service->trim_text($i->customer_name);
						$update_cus->customer_type = $this->qdc_service->trim_text($i->customer_type);
						$update_cus->industry_class = $this->qdc_service->trim_text($i->industry_class);
						$update_cus->updated_by = Auth::id();
						try {
							$update_cus->save();
						} catch (Exception $e) {
							$errors[] = ['customer_code' => $i->customer_code, 'errors' => ['validate' => substr($e,0,254)]];
						}

 						DB::table('customer_position')->where('customer_id', '=', $cus[0]->customer_id);

 						if(!empty($pc)) {
 							foreach ($pc as $key => $value) {
 								if(!empty($value)) {
	 								$cp = new CustomerPosition;
	 								$cp->customer_id = $cus[0]->customer_id;
	 								$cp->position_code = $this->qdc_service->trim_text($value);
	 								try {
	 									$cp->save();
	 								} catch (Exception $e) {
	 									$errors[] = ['customer_code' => $i->customer_code, 'errors' => ['validate' => substr($e,0,254)]];
	 								}
 								}
 							}
 						}
					}
				}
			}
		}
		return response()->json(['status' => 200, 'errors' => $errors]);
	}

	public function show($customer_id)
	{
		try {
			$item = Customer::findOrFail($customer_id);
			$item->customer_position = CustomerPosition::select('position_code')->where('customer_id', $customer_id)->get();
		} catch (ModelNotFoundException $e) {
			return response()->json(['status' => 404, 'data' => 'Customer not found.']);
		}
		return response()->json($item);
	}

	// public function update(Request $request, $customer_id)
	// {
	// 	try {
	// 		$item = Customer::findOrFail($customer_id);
	// 	} catch (ModelNotFoundException $e) {
	// 		return response()->json(['status' => 404, 'data' => 'Customer not found.']);
	// 	}

	// 	$validator = Validator::make($request->all(), [
	// 		'customer_code' => 'required|max:20',
	// 		'customer_name' => 'required|max:255',
	// 		'customer_type' => 'required|max:255',
	// 		'industry_class' => 'required|max:10'
	// 	]);

	// 	if ($validator->fails()) {
	// 		return response()->json(['status' => 400, 'data' => $validator->errors()]);
	// 	} else {
	// 		$item->fill($request->all());
	// 		$item->updated_by = Auth::id();
	// 		$item->save();
	// 	}

	// 	return response()->json(['status' => 200, 'data' => $item]);

	// }

	// public function destroy($customer_id)
	// {
	// 	try {
	// 		$item = Customer::findOrFail($customer_id);
	// 	} catch (ModelNotFoundException $e) {
	// 		return response()->json(['status' => 404, 'data' => 'Customer not found.']);
	// 	}

	// 	try {
	// 		$item->delete();
	// 	} catch (Exception $e) {
	// 		if ($e->errorInfo[1] == 1451) {
	// 			return response()->json(['status' => 400, 'data' => 'Cannot delete because this Customer is in use.']);
	// 		} else {
	// 			return response()->json($e->errorInfo);
	// 		}
	// 	}

	// 	return response()->json(['status' => 200]);

	// }

}
