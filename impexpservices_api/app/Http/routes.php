<?php

/*
|--------------------------------------------------------------------------
| Application Routes
|--------------------------------------------------------------------------
|
| Here is where you can register all of the routes for an application.
| It's a breeze. Simply tell Laravel the URIs it should respond to
| and give it the controller to call when that URI is requested.
|
*/

Route::get('/', function () {
    return view('welcome');
});
Route::get('breweries', ['middleware' => 'cors', function()
{
    return \Response::json(\App\Brewery::with('beers', 'geocode')->paginate(10), 200);
	
}]);

Route::get('appraisal-structure/get', 'AppraisalStructureController@structure');
Route::get('appraisal-template/get', 'AppraisalStructureController@template');

Route::get('appraisal/master/export', 'AppraisalExpImpController@exportMaster');
Route::POST('appraisal/master/import', 'AppraisalExpImpController@importMaster');
Route::get('appraisal/detail/export', 'AppraisalExpImpController@exportDetail');
Route::POST('appraisal/detail/import', 'AppraisalExpImpController@importDetail');
