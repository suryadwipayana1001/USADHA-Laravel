<?php

use Illuminate\Http\Request;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

// Route::group(['prefix' => 'v1', 'as' => 'admin.', 'namespace' => 'Api\V1\Admin'], function () {
//     Route::apiResource('permissions', 'PermissionsApiController');

//     Route::apiResource('roles', 'RolesApiController');

//     Route::apiResource('users', 'UsersApiController');

//     Route::apiResource('products', 'ProductsApiController');
// });

Route::group(['prefix' => 'open', 'namespace' => 'Api\V1\Admin'], function () {
    Route::post('/login', 'CustomersApiController@login');
    Route::post('/loginagent', 'CustomersApiController@loginagent');
    Route::post('/register', 'CustomersApiController@register');
    Route::post('/register-agent', 'CustomersApiController@registerAgent');
    Route::get('/logout', 'CustomersApiController@logout')->middleware('auth:api');
    Route::post('/reset', 'CustomersApiController@resetUser');
    Route::post('/user-block', 'CustomersApiController@userBlock');
    Route::get('/agents', 'CustomersApiController@agentsOpen');
});

Route::group(['prefix' => 'close', 'namespace' => 'Api\V1\Admin', 'middleware' => 'auth:api'], function () {
    Route::get('/products', 'ProductsApiController@index');
    Route::get('product/{id}', 'ProductsApiController@show');
    Route::post('topup', 'TopupsApiController@store');
    Route::post('activate', 'CustomersApiController@activate');
    Route::get('/packages', 'PackagesApiController@index');
    Route::get('package/{id}', 'PackagesApiController@show');
    Route::get('/accountcashs', 'AccountsApiController@cash');
    Route::get('account/{id}', 'AccountsApiController@show');
    Route::get('/agents', 'CustomersApiController@agents');
    Route::get('agent/{id}', 'CustomersApiController@agentshow');
    Route::get('balance/{id}', 'TopupsApiController@balance');
    Route::post('order', 'OrdersApiController@store');
    Route::get('history/{id}', 'TopupsApiController@history');
    Route::post('member-show', 'CustomersApiController@membershow');
    Route::post('transfer', 'TopupsApiController@transfer');
    Route::get('members', 'CustomersApiController@members');
    Route::post('order-agent', 'OrdersApiController@storeAgent');
    Route::post('/update-profile', 'CustomersApiController@updateprofile');
    Route::get('history-order/{id}', 'OrdersApiController@history');
    Route::get('history-order-agent/{id}', 'OrdersApiController@historyAgent');
    Route::get('product-stock/{id}', 'ProductsApiController@stockMember');
    Route::get('/packages/{id}', 'PackagesApiController@packages');
    Route::post('activate-agent', 'CustomersApiController@activateAgent');
    Route::post('withdraw', 'TopupsApiController@withdraw');
    Route::get('downline/{id}', 'CustomersApiController@downline');
    Route::post('/register-downline', 'CustomersApiController@registerDownline');
    Route::get('order-agent-process/{id}', 'OrdersApiController@orderAgentProcess');
    Route::get('order-cancel/{id}', 'OrdersApiController@orderCancel');
    Route::get('delivery-agent-update/{id}', 'OrdersApiController@deliveryAgentUpdate');
    Route::get('delivery-member-update/{id}', 'OrdersApiController@deliveryMemberUpdate');
    Route::get('/products-member', 'ProductsApiController@indexMember');
    Route::get('/products-agent', 'ProductsApiController@indexAgent');   
    Route::post('/logs', 'CustomersApiController@logs'); 
    Route::post('/logs-unread', 'CustomersApiController@logsUnread');
    Route::get('logs-update-status/{id}', 'CustomersApiController@logsUpdate');
    Route::post('/upload-img/{id}', 'CustomersApiController@upImg');
    Route::get('test/{id}', 'OrdersApiController@test');
    // Route::post('storeBlog', 'API\BlogController@store');
    // Route::put('updateBlog/{id}', 'API\BlogController@update');
    // Route::get('showBlog/{id}', 'API\BlogController@show');
    // Route::delete('deleteBlog/{id}', 'API\BlogController@destroy');
});
