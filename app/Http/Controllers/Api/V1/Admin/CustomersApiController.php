<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Activation;
use App\CustomerApi;
use App\Http\Controllers\Controller;
use App\Ledger;
use App\LogNotif;
use App\Mail\MemberEmail;
use App\Mail\ResetEmail;
use App\Member;
use App\NetworkFee;
use App\Order;
use App\OrderDetails;
use App\OrderPoint;
use App\Package;
use App\Product;
use App\Traits\TraitModel;
use Auth;
use Berkayk\OneSignal\OneSignalClient;
use Hashids;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use OneSignal;
use Validator;
use App\Province;
use App\City;

class CustomersApiController extends Controller
{

    use TraitModel;
    private $onesignal_client;

    public function __construct()
    {
        $this->onesignal_client = new OneSignalClient(env('ONESIGNAL_APP_ID_MEMBER'), env('ONESIGNAL_REST_API_KEY_MEMBER'), '');
    }

    public function logsUpdate($id)
    {
        $logs = LogNotif::find($id);

        $logs->status = 'read';
        $logs->save();
        return response()->json([
            'success' => true,
            'message' => 'Update Log Status is success.',
        ]);

    }

    public function logsUnread(Request $request)
    {
        $logs = LogNotif::where('customers_id', $request->customers_id)
            ->where('status', 'unread')
            ->get();
        return response()->json([
            'success' => true,
            'count' => $logs->count(),
        ]);
    }

    public function logs(Request $request)
    {
        $logs = LogNotif::where('customers_id', $request->customers_id)
            ->orderBy("id", "desc")
            ->get();
        if (!empty($logs)) {
            return response()->json([
                'success' => true,
                'data' => $logs,
            ]);
        } else {
            return response()->json([
                'success' => false,
                'message' => 'Log is empty',
            ], 401);
        }
    }

    public function upImg($id, Request $request)
    {
        $member = Member::find($id);
        $img_path = "/images/users";
        if ($request->img != null) {
            $resource = $request->img;
            $name = strtolower($member->code);
            // $img_nama=$request->img->filename;
            // $filename_arr = explode(".", $filename);
            // $filename_count=count($filename_arr);
            // //return $img_nama;
            // $file_ext=$filename_arr[$filename_count-1];
            $file_ext = $request->img->extension();
            $name = str_replace(" ", "-", $name);
            $img_name = $img_path . "/" . $name . "-" . $member->id . "." . $file_ext;

            //unlink old
            $resource->move(\base_path() . $img_path, $img_name);
            $member->img = $img_name;
            $member->save();
            return response()->json([
                'success' => true,
                'message' => 'Update Image Profile is Success.',
                'data' => $member,
            ]);
        } else {
            return response()->json([
                'success' => false,
                'message' => 'Image is null',
            ], 401);
        }
    }

    public function upImgRJS($id, Request $request)
    {
        return response()->json([
            'success' => true,
            'message' => 'Update Image Profile is Success.',
            'data' => $request,
        ]);
    }

    public function resetUser(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors(),
            ], 401);
        }

        $user = Member::where('email', $request->input('email'))->first();

        if (empty($user)) {
            $message = 'Reset gagal, Email tidak dikenali.';
            $status = false;
            return response()->json([
                'status' => $status,
                'message' => $message,
            ]);
        } else {
            $password = passw_gnr(7);
            $password_ency = bcrypt($password);
            $user->password = $password_ency;
            $user->save();
            foreach ($user as $key => $value) {
                $user->password_raw = $password;
            }
            Mail::to($request->input('email'))->send(new ResetEmail($user));
            $message = 'Reset berhasil, Password baru telah terkirim ke Email.';
            return response()->json([
                'success' => true,
                'message' => $message,
            ]);
        }
    }

    public function members()
    {
        try {
            $members = CustomerApi::select('*')
                ->get();
        } catch (QueryException $exception) {
            return response()->json([
                'success' => false,
                'message' => 'Data Kosong.',
            ], 401);
        }

        return response()->json([
            'success' => true,
            'data' => $members,
        ]);
    }

    public function downline($id)
    {
        $user = CustomerApi::where('ref_id', $id)
            ->where('type', 'member')
            ->with('activations')
            ->with('refferal')
            ->orderBy('activation_at', 'ASC')
            ->get();
        if (!empty($user)) {
            return response()->json([
                'success' => true,
                'data' => $user,
            ]);
        } else {
            return response()->json([
                'success' => false,
                'message' => 'Data is empty.',
            ], 401);
        }
    }

    public function membershow(Request $request)
    {
        $user = CustomerApi::where('phone', $request->phone)->first();
        if (!empty($user)) {
            return response()->json([
                'success' => true,
                'data' => $user,
            ]);
        } else {
            return response()->json([
                'success' => false,
                'message' => 'Invalid Phone Number',
            ], 401);
        }
    }

    public function login()
    {

        $user = CustomerApi::where('email', request('email'))
            ->where('type', 'member')
            ->with(['activations', 'refferal', 'provinces', 'city'])
            ->first();
        if (!empty($user)) {
            if ((Hash::check(request('password'), $user->password)) && ($user->status_block == 0)) {
                Auth::login($user);
                if (request('id_onesignal') != null) {
                    $user->id_onesignal = request('id_onesignal');
                    $user->save();
                }
                $success['token'] = Auth::user()->createToken('authToken')->accessToken;
                //After successfull authentication, notice how I return json parameters
                $user->ref_link = "https://admin.belogherbal.com/member?ref=" . Hashids::encode($user->id);
                return response()->json([
                    'success' => true,
                    'token' => $success,
                    'user' => $user,
                ]);
            } else {
                //if authentication is unsuccessfull, notice how I return json parameters
                $message = 'Email & Password yang Anda masukkan salah. Salah memasukkan Email & Password lebih dari 3x maka Account akan otomatis di blokir.';
                if ($user->status_block == 1) {
                    $message = 'Your Account is temporary blocked.';
                }
                return response()->json([
                    'success' => false,
                    'message' => $message,
                ], 401);
            }} else {
            $message = 'Email & Password yang Anda masukkan salah. Salah memasukkan Email & Password lebih dari 3x maka Account akan otomatis di blokir.';
            return response()->json([
                'success' => false,
                'message' => $message,
            ], 401);
        }
    }

    public function loginagent()
    {

        $user = CustomerApi::where('email', request('email'))
            ->where('type', 'agent')
            ->with(['provinces', 'city'])
            ->first();
        if ((Hash::check(request('password'), $user->password)) && ($user->status_block == 0)) {
            Auth::login($user);
            if (request('id_onesignal') != null) {
                $user->id_onesignal = request('id_onesignal');
                $user->save();
            }
            $success['token'] = Auth::user()->createToken('authToken')->accessToken;
            //After successfull authentication, notice how I return json parameters
            return response()->json([
                'success' => true,
                'token' => $success,
                'user' => $user,
            ]);
        } else {
            //if authentication is unsuccessfull, notice how I return json parameters
            $message = 'Email & Password yang Anda masukkan salah. Salah memasukkan Email & Password lebih dari 3x maka Account akan otomatis di blokir.';
            if ($user->status_block == 1) {
                $message = 'Your Account is temporary blocked.';
            }
            return response()->json([
                'success' => false,
                'message' => $message,
            ], 401);
        }
    }

    public function userBlock(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors(),
            ], 401);
        }

        $user = Member::where('email', $request->input('email'))->first();
        if (empty($user)) {
            $message = 'Update Gagal.';
            $status = false;
            return response()->json([
                'status' => $status,
                'message' => $message,
            ]);
        } else {
            $user->status_block = '1';
            $user->save();
            //response
            $message = 'Update Berhasil.';
            $status = true;
            return response()->json([
                'status' => $status,
                'message' => $message,
                'data' => $user,
            ]);
        }
    }

    /**
     * Register api.
     *
     * @return \Illuminate\Http\Response
     */
    public function updateprofile(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required',
            'name' => 'required',
            //'phone' => 'required|unique:customers|regex:/(0)[0-9]{10}/',
            'phone' => 'required',
            //'email' => 'required|email|unique:customers',
            'email' => 'required|email',
            'password' => 'required',
            'address' => 'required',
            'lat' => 'required',
            'lng' => 'required',
            'province_id' => 'required',
            'city_id' => 'required'
        ]);
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors(),
            ], 401);
        }
        $input = $request->all();
        // $member = CustomerApi::with('activations')->where('id', $input['id'])->first();
        $member = CustomerApi::with(['activations', 'provinces', 'city'])->where('id', $input['id'])->first();
        $password_raw = $input['password'];
        $input['password'] = bcrypt($input['password']);
        $member->password = $input['password'];
        $member->name = $input['name'];
        $member->phone = $input['phone'];
        $member->email = $input['email'];
        $member->address = $input['address'];
        $member->lat = $input['lat'];
        $member->lng = $input['lng'];
        $member->province_id= $input['province_id'];
        $member->city_id= $input['city_id'];
        try {
            $member->save();
        } catch (QueryException $exception) {
            return response()->json([
                'success' => false,
                'message' => 'Duplicate Email or Phone Number.',
            ], 401);
        }

        foreach ($member as $key => $value) {
            $member->password_raw = $password_raw;
        }
        $member->ref_link = "https://admin.belogherbal.com/member?ref=" . Hashids::encode($member->id);
        Mail::to($request->input('email'))->send(new MemberEmail($member));
        return response()->json([
            'success' => true,
            'data' => $member,
        ]);
    }

    /**
     * Register api.
     *
     * @return \Illuminate\Http\Response
     */
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required',
            //'phone' => 'required|unique:customers|regex:/(0)[0-9]{10}/',
            'phone' => 'required',
            //'email' => 'required|email|unique:customers',
            'email' => 'required|email',
            'password' => 'required',
            'register' => 'required',
            'address' => 'required',
            'province_id' => 'required',
            'city_id' => 'required'
        ]);
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors(),
            ], 401);
        }
        $input = $request->all();
        $last_code = $this->mbr_get_last_code();
        $code = acc_code_generate($last_code, 8, 3);
        $password_raw = $input['password'];
        $input['password'] = bcrypt($input['password']);
        $input['code'] = $code;
        $input['type'] = 'member';
        $input['status'] = 'pending';
        if (!isset($input['customers_id'])) {
            $ref_def_id = Member::select('id')
                ->Where('def', '=', '1')
                ->get();
            $referals_id = $ref_def_id[0]->id;
            $parent_id = $this->set_parent($referals_id);
            $input['parent_id'] = $parent_id;
            $input['ref_id'] = $referals_id;
        }

        //check ref_id
        $ref_row = Member::find($input['ref_id']);
        if ($ref_row->status != 'active') {
            return response()->json([
                'success' => false,
                'message' => 'Register Gagal, Status Referal belum Activasi.',
            ], 401);
        }

        try {
            $user = CustomerApi::create($input);
            $success['token'] = $user->createToken('appToken')->accessToken;
            foreach ($user as $key => $value) {
                $user->password_raw = $password_raw;
            }
            Mail::to($request->input('email'))->send(new MemberEmail($user));
            return response()->json([
                'success' => true,
                'token' => $success,
                'user' => $user,
            ]);
        } catch (QueryException $exception) {
            return response()->json([
                'success' => false,
                'message' => 'Duplicate Email or Phone Number.',
            ], 401);
        }
    }

    /**
     * Register api.
     *
     * @return \Illuminate\Http\Response
     */
    public function registerDownline(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required',
            //'phone' => 'required|unique:customers|regex:/(0)[0-9]{10}/',
            'phone' => 'required',
            //'email' => 'required|email|unique:customers',
            'email' => 'required|email',
            'password' => 'required',
            'register' => 'required',
            'address' => 'required',
            'ref_id' => 'required',
            'package_id' => 'required',
            'agents_id' => 'required',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors(),
            ], 401);
        }

        //check ref_id
        $ref_row = Member::find($request->ref_id);
        if ($ref_row->status != 'active') {
            return response()->json([
                'success' => false,
                'message' => 'Register Gagal, Status Referal belum Activasi.',
            ], 401);
        }

        /* point balance */
        //get point referal
        $points_id = 1;
        $points_upg_id = 2;
        $points_debit = OrderPoint::where('customers_id', '=', $request->ref_id)
            ->where('type', '=', 'D')
            ->sum('amount');
        $points_credit = OrderPoint::where('customers_id', '=', $request->ref_id)
            ->where('type', '=', 'C')
            ->sum('amount');
        $points_balance = $points_debit - $points_credit;

        //get package price & cogs
        $package = Product::select('price', 'cogs', 'bv', 'activation_type_id')
            ->where('id', '=', $request->input('package_id'))
            ->get();
        $package = json_decode($package, false);
        $cogs_total = $package[0]->cogs;
        $bv_total = $package[0]->bv;
        $total = $package[0]->price;
        $package_activation_type_id = $package[0]->activation_type_id;
        $profit = $total - $cogs_total;

        if ($points_balance >= $total) {
            $input = $request->all();
            $last_code = $this->mbr_get_last_code();
            $code = acc_code_generate($last_code, 8, 3);
            $password_raw = $input['password'];
            $input['password'] = bcrypt($input['password']);
            $input['code'] = $code;
            $input['type'] = 'member';
            $input['status'] = 'pending';
            $parent_id = $this->set_parent($input['ref_id']);
            $input['parent_id'] = $parent_id;

            try {
                $user = CustomerApi::create($input);
                $member = $user;
            } catch (QueryException $exception) {
                return response()->json([
                    'success' => false,
                    'message' => 'Duplicate Email or Phone Number.',
                ], 401);
            }

            //init
            $register = $request->input('register');
            $memo = 'Aktivasi Member ' . $member->code . "-" . $member->name;
            /* proceed ledger */
            $data = ['register' => $register, 'title' => $memo, 'memo' => $memo, 'status' => 'pending'];
            $ledger = Ledger::create($data);
            $ledger_id = $ledger->id;
            //set ledger entry arr
            //get cashback 01
            //CBA 1
            $networkfee1_row = NetworkFee::select('*')
                ->Where('code', '=', 'CBA01')
                ->get();
            $cba1 = (($networkfee1_row[0]->amount) / 100) * $total;
            //CBA 2
            $networkfee2_row = NetworkFee::select('*')
                ->Where('code', '=', 'CBA02')
                ->get();
            $cba2 = (($networkfee2_row[0]->amount) / 100) * $total;
            //check type package activation
            $package_obj = Package::find($request->input('package_id'));
            //set ref fee
            $ref_fee_row = NetworkFee::select('*')
                ->Where('type', '=', 'activation')
                ->Where('activation_type_id', '=', $package_obj->activation_type_id)
                ->get();
            //BVCV
            $bvcv_row = NetworkFee::select('*')
                ->Where('code', '=', 'BVCV')
                ->get();
            //BVPO
            $bvpo_row = NetworkFee::select('*')
                ->Where('code', '=', 'BVPO')
                ->get();
            $bvcv = (($bvcv_row[0]->amount) / 100) * $bv_total;
            $bv_nett = $bv_total - $bvcv;
            $sbv = (($ref_fee_row[0]->sbv) / 100) * $bv_nett;
            //package activation type
            $package_activation_row = Activation::select('type', 'bv_min', 'bv_max')
                ->Where('id', '=', $package_activation_type_id)
                ->get();
            //get sbv ref 1
            $ref1_row = Member::find($member->ref_id);
            $ref1_fee_point_sale = 0;
            $ref1_fee_point_upgrade = 0;
            $ref1_flush_out = 0;
            if (!empty($ref1_row) && $ref1_row->ref_id > 0) {
                $rsbv_g1 = (($ref_fee_row[0]->rsbv_g1) / 100) * $sbv;
                $ref1_fee_point_sale = $rsbv_g1;
                //ref1 activation type
                $ref1_activation_row = Activation::select('type', 'bv_min', 'bv_max')
                    ->Where('id', '=', $ref1_row->activation_type_id)
                    ->get();
                //set ref 1 fee
                $ref1_fee_row = NetworkFee::select('*')
                    ->Where('type', '=', 'activation')
                    ->Where('activation_type_id', '=', $ref1_row->activation_type_id)
                    ->get();
                //if ref 1 buseness
                if ($ref1_activation_row[0]->type == 'business' && ($ref1_activation_row[0]->bv_min < $package_activation_row[0]->bv_min)) {
                    $bv_total_max = $ref1_activation_row[0]->bv_max * $bvpo_row[0]->amount;
                    $bvcv_max = (($bvcv_row[0]->amount) / 100) * $bv_total_max;
                    $bv_nett_max = $bv_total_max - $bvcv_max;
                    $sbv_max = (($ref1_fee_row[0]->sbv) / 100) * $bv_nett_max;
                    $ref1_fee_point_sale = (($ref1_fee_row[0]->rsbv_g1) / 100) * $sbv_max;
                    $ref1_flush_out = $rsbv_g1 - $ref1_fee_point_sale;
                }
                //if ref 1 user
                if ($ref1_activation_row[0]->type == 'user' && ($ref1_activation_row[0]->bv_min < $package_activation_row[0]->bv_min)) {
                    $bv_total_max = $ref1_activation_row[0]->bv_max * $bvpo_row[0]->amount;
                    $bvcv_max = (($bvcv_row[0]->amount) / 100) * $bv_total_max;
                    $bv_nett_max = $bv_total_max - $bvcv_max;
                    $sbv_max = (($ref1_fee_row[0]->sbv) / 100) * $bv_nett_max;
                    $ref1_fee_point_sale = (($ref1_fee_row[0]->rsbv_g1) / 100) * $sbv_max;
                    $ref1_fee_point_upgrade = $rsbv_g1 - $ref1_fee_point_sale;
                }}
            //get sbv ref 2
            $ref2_row = Member::find($ref1_row->ref_id);
            $ref2_fee_point_sale = 0;
            $ref2_fee_point_upgrade = 0;
            if (!empty($ref2_row) && $ref2_row->ref_id > 0) {
                $rsbv_g2 = (($ref_fee_row[0]->rsbv_g2) / 100) * $sbv;
                $ref2_fee_point_sale = $rsbv_g2;
                //package_activation_type ref1
                $ref2_activation_row = Activation::select('type', 'bv_min', 'bv_max')
                    ->Where('id', '=', $ref2_row->activation_type_id)
                    ->get();
                //set ref 2 fee
                $ref2_fee_row = NetworkFee::select('*')
                    ->Where('type', '=', 'activation')
                    ->Where('activation_type_id', '=', $ref2_row->activation_type_id)
                    ->get();
                //if ref 2 buseness
                if ($ref2_activation_row[0]->type == 'business' && ($ref2_activation_row[0]->bv_min < $package_activation_row[0]->bv_min)) {
                    $bv_total_max = $ref2_activation_row[0]->bv_max * $bvpo_row[0]->amount;
                    $bvcv_max = (($bvcv_row[0]->amount) / 100) * $bv_total_max;
                    $bv_nett_max = $bv_total_max - $bvcv_max;
                    $sbv_max = (($ref2_fee_row[0]->sbv) / 100) * $bv_nett_max;
                    $ref2_fee_point_sale = (($ref2_fee_row[0]->rsbv_g2) / 100) * $sbv_max;
                    $ref1_flush_out = 0;
                }
                //if ref 2 user
                if ($ref2_activation_row[0]->type == 'user' && ($ref2_activation_row[0]->bv_min < $package_activation_row[0]->bv_min)) {
                    $bv_total_max = $ref2_activation_row[0]->bv_max * $bvpo_row[0]->amount;
                    $bvcv_max = (($bvcv_row[0]->amount) / 100) * $bv_total_max;
                    $bv_nett_max = $bv_total_max - $bvcv_max;
                    $sbv_max = (($ref2_fee_row[0]->sbv) / 100) * $bv_nett_max;
                    $ref2_fee_point_sale = (($ref2_fee_row[0]->rsbv_g2) / 100) * $sbv_max;
                    $ref2_fee_point_upgrade = $rsbv_g2 - $ref2_fee_point_sale;
                    $ref1_flush_out = 0;
                }
            }

            //set order
            $warehouses_id = 1;
            $last_code = $this->get_last_code('order-agent');
            $order_code = acc_code_generate($last_code, 8, 3);
            $data = array('memo' => $memo, 'total' => $total, 'type' => 'activation_member', 'status' => 'pending', 'ledgers_id' => $ledger_id, 'customers_id' => $request->input('ref_id'), 'agents_id' => $request->input('agents_id'), 'payment_type' => 'point', 'code' => $order_code, 'register' => $register, 'bv_activation_amount' => $bv_nett, 'customers_activation_id' => $member->id);
            $order = Order::create($data);
            //set order products
            $order->products()->attach($request->input('package_id'), ['quantity' => 1, 'price' => $total, 'cogs' => $cogs_total]);
            //set order order details (inventory stock)
            $package_items = Package::with('products')
                ->where('id', $request->input('package_id'))
                ->get();
            $package_items = json_decode($package_items, false);
            $package_items = $package_items[0]->products;
            //loop items
            foreach ($package_items as $key => $value) {
                $order->productdetails()->attach($value->id, ['quantity' => $value->pivot->quantity, 'type' => 'D', 'status' => 'onhold', 'warehouses_id' => $warehouses_id, 'owner' => $member->id]);
                $order->productdetails()->attach($value->id, ['quantity' => $value->pivot->quantity, 'type' => 'C', 'status' => 'onhold', 'warehouses_id' => $warehouses_id, 'owner' => $request->input('agents_id')]);
            }

            /*update member */
            $parent_id = $this->set_parent($member->ref_id);
            $activation_at = date('Y-m-d H:i:s');
            $member->parent_id = $parent_id;
            $member->activation_at = $activation_at;
            $member->status = 'active';
            $member->activation_type_id = $package_activation_type_id;
            $member->save();
            /*set order*/
            //set def
            $referal_id = $request->input('ref_id');
            $agents_id = $request->input('agents_id');
            $warehouses_id = 1;
            $com_row = Member::select('*')
                ->where('def', '=', '1')
                ->get();
            $com_id = $com_row[0]->id;

            //PAIRING
            $fee_pairing = $this->pairing($order->id, $member->ref_id);

            //get profit
            $bvcv = (($bvcv_row[0]->amount) / 100) * $bv_total;
            $bv_nett = $bv_total - $bvcv;
            $profit_com = $bv_nett - $cba1 - $cba2 - $ref1_fee_point_sale - $ref1_fee_point_upgrade - $ref2_fee_point_sale - $ref2_fee_point_upgrade - $fee_pairing - $ref1_flush_out;

            //set account
            $acc_points = '67'; //utang poin
            $acc_res_cashback = '70';
            $acc_profit = '71';
            $reserve_amount = $bv_nett - $cba1;
            $points_amount = $reserve_amount - $profit_com;
            $profit_type='C';
            if($profit_com<0){
                $acc_profit = '70';
                $profit_type='D';
                $profit_com=$profit_com * -1;
            }
            $accounts = array($acc_points, $acc_res_cashback, $acc_profit);
            $amounts = array($points_amount, $reserve_amount, $profit_com);
            $types = array('C', 'D', $profit_type);
            //ledger entries
            for ($account = 0; $account < count($accounts); $account++) {
                if ($accounts[$account] != '') {
                    $ledger->accounts()->attach($accounts[$account], ['entry_type' => $types[$account], 'amount' => $amounts[$account]]);
                }
            }

            //set trf points from member to Usadha Bhakti (pending points)
            $order->points()->attach($points_id, ['amount' => $total, 'type' => 'D', 'status' => 'onhand', 'memo' => 'Penambahan Poin dari (Pending Order) ' . $memo, 'customers_id' => $com_id]);
            $order->points()->attach($points_id, ['amount' => $total, 'type' => 'C', 'status' => 'onhand', 'memo' => 'Pemotongan Poin dari ' . $memo, 'customers_id' => $referal_id]);

            //set trf points cashback agent
            $order->points()->attach($points_id, ['amount' => $cba2, 'type' => 'D', 'status' => 'onhold', 'memo' => 'Penambahan Poin (Cashback Agen 02) dari ' . $memo, 'customers_id' => $agents_id]);
            //set trf points from member to agent (onhold)
            $order->points()->attach($points_id, ['amount' => $total, 'type' => 'D', 'status' => 'onhold', 'memo' => 'Penambahan Poin dari (Penjualan Paket) ' . $memo, 'customers_id' => $agents_id]);

            //set ref1 fee
            //point sale
            if ($ref1_fee_point_sale > 0) {
                $order->points()->attach($points_id, ['amount' => $ref1_fee_point_sale, 'type' => 'D', 'status' => 'onhold', 'memo' => 'Poin Komisi (Refferal) dari ' . $memo, 'customers_id' => $member->ref_id]);
            }
            //point upgrade
            if ($ref1_fee_point_upgrade > 0) {
                $order->points()->attach($points_upg_id, ['amount' => $ref1_fee_point_upgrade, 'type' => 'D', 'status' => 'onhold', 'memo' => 'Poin (Upgrade) Komisi (Refferal) dari ' . $memo, 'customers_id' => $member->ref_id]);
            }

            //set ref2 fee
            //point sale
            if ($ref2_fee_point_sale > 0) {
                $order->points()->attach($points_id, ['amount' => $ref2_fee_point_sale, 'type' => 'D', 'status' => 'onhold', 'memo' => 'Poin Komisi (Refferal) dari ' . $memo, 'customers_id' => $ref2_row->id]);
            }
            //point upgrade
            if ($ref2_fee_point_upgrade > 0) {
                $order->points()->attach($points_upg_id, ['amount' => $ref2_fee_point_upgrade, 'type' => 'D', 'status' => 'onhold', 'memo' => 'Poin (Upgrade) Komisi (Refferal) dari ' . $memo, 'customers_id' => $ref2_row->id]);
            }
            //point flush out
            if ($ref1_flush_out > 0) {
                $order->points()->attach($points_id, ['amount' => $ref1_flush_out, 'type' => 'D', 'status' => 'onhold', 'memo' => 'Poin Komisi (Flush Out) dari ' . $memo, 'customers_id' => $ref2_row->id]);
            }

            //push notif to agent
            $user_os = CustomerApi::find($agents_id);
            $id_onesignal = $user_os->id_onesignal;
            $memo = 'Order Masuk dari ' . $memo;
            $register = date("Y-m-d");
            //store to logs_notif
            $data = ['register' => $register, 'customers_id' => $agents_id, 'memo' => $memo];
            $logs = LogNotif::create($data);
            //push notif
            if (!empty($id_onesignal)) {
                OneSignal::sendNotificationToUser(
                    $memo,
                    $id_onesignal,
                    $url = null,
                    $data = null,
                    $buttons = null,
                    $schedule = null
                );
            }

            foreach ($user as $key => $value) {
                $user->password_raw = $password_raw;
            }

            Mail::to($request->input('email'))->send(new MemberEmail($user));

            return response()->json([
                'success' => true,
                'user' => $user,
            ]);
        } else {
            return response()->json([
                'success' => false,
                'message' => 'Saldo Poin Member Tidak Mencukupi.',
            ], 401);
        }
    }

    /**
     * Register api.
     *
     * @return \Illuminate\Http\Response
     */
    public function registerAgent(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required',
            //'phone' => 'required|unique:customers|regex:/(0)[0-9]{10}/',
            'phone' => 'required',
            //'email' => 'required|email|unique:customers',
            'email' => 'required|email',
            'password' => 'required',
            'register' => 'required',
            'address' => 'required',
            'province_id' => 'required',
            'city_id' => 'required'
        ]);
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors(),
            ], 401);
        }
        $input = $request->all();
        $last_code = $this->get_last_code('agent');
        $code = acc_code_generate($last_code, 8, 3);
        $password_raw = $input['password'];
        $input['password'] = bcrypt($input['password']);
        $input['code'] = $code;
        $input['type'] = 'agent';
        $input['status'] = 'pending';
        $input['parent_id'] = 0;
        $input['ref_id'] = 0;

        try {
            $user = CustomerApi::create($input);
            $member = $user;
        } catch (QueryException $exception) {
            return response()->json([
                'success' => false,
                'message' => 'Duplicate Email or Phone Number.',
            ], 401);
        }

        $success['token'] = $user->createToken('appToken')->accessToken;
        foreach ($user as $key => $value) {
            $user->password_raw = $password_raw;
        }
        Mail::to($request->input('email'))->send(new MemberEmail($user));
        return response()->json([
            'success' => true,
            'token' => $success,
            'user' => $user,
        ]);
    }

    public function upgrade(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required',
            'package_id' => 'required',
            'agents_id' => 'required',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors(),
            ], 401);
        } else {
            //set member
            $member = Member::find($request->input('id'));
            //get point member
            $points_id = 1;
            $points_upg_id = 2;
            $points_debit = OrderPoint::where('customers_id', '=', $request->input('id'))
                ->where('type', '=', 'D')
                ->where('status', '=', 'onhand')
                ->sum('amount');
            $points_credit = OrderPoint::where('customers_id', '=', $request->input('id'))
                ->where('type', '=', 'C')
                ->where('status', '=', 'onhand')
                ->sum('amount');
            $points_balance = $points_debit - $points_credit;

            //get package price & cogs
            $package = Product::select('price', 'cogs', 'bv', 'activation_type_id', 'upgrade_type_id')
                ->where('id', '=', $request->input('package_id'))
                ->get();
            $package = json_decode($package, false);
            $cogs_total = $package[0]->cogs;
            $bv_total = $package[0]->bv;
            $total = $package[0]->price;
            $package_activation_type_id = $package[0]->activation_type_id;
            $package_upgrade_type_id = $package[0]->upgrade_type_id;
            $fee_upgrade_type_id = $package_upgrade_type_id - 1;
            $profit = $total - $cogs_total;

            //get stock agent, loop package
            $stock_status = 'true';
            $package_items = Package::with('products')
                ->where('id', $request->input('package_id'))
                ->get();
            $package_items = json_decode($package_items, false);
            $package_items = $package_items[0]->products;
            //loop items
            foreach ($package_items as $key => $value) {
                //get qty package product & compare sum stock
                $stock_debit = OrderDetails::where('owner', '=', $request->input('agents_id'))
                    ->where('type', '=', 'D')
                    ->where('status', '=', 'onhand')
                    ->where('products_id', $value->id)
                    ->sum('quantity');
                $stock_credit = OrderDetails::where('owner', '=', $request->input('agents_id'))
                    ->where('type', '=', 'C')
                    ->where('status', '=', 'onhand')
                    ->where('products_id', $value->id)
                    ->sum('quantity');
                $stock_balance = $stock_debit - $stock_credit;
                if ($stock_balance < $value->pivot->quantity) {
                    $stock_status = 'false';
                }
            }

            //compare total to point belanja
            if ($points_balance >= $total) {
                //init
                $register = date("Y-m-d");
                $memo = 'Upgrade Member ' . $member->code . "-" . $member->name;
                /* proceed ledger */
                $data = ['register' => $register, 'title' => $memo, 'memo' => $memo, 'status' => 'pending'];
                $ledger = Ledger::create($data);
                $ledger_id = $ledger->id;
                //set ledger entry arr
                //CBA 1
                $networkfee1_row = NetworkFee::select('*')
                    ->Where('code', '=', 'CBA01')
                    ->get();
                $cba1 = (($networkfee1_row[0]->amount) / 100) * $total;
                //CBA 2
                $networkfee2_row = NetworkFee::select('*')
                    ->Where('code', '=', 'CBA02')
                    ->get();
                $cba2 = (($networkfee2_row[0]->amount) / 100) * $total;
                //check type package activation
                $package_obj = Package::find($request->input('package_id'));
                //set ref fee
                $ref_fee_row = NetworkFee::select('*')
                    ->Where('type', '=', 'activation')
                    ->Where('activation_type_id', '=', $fee_upgrade_type_id)
                    ->get();
                //BVCV
                $bvcv_row = NetworkFee::select('*')
                    ->Where('code', '=', 'BVCV')
                    ->get();
                //BVPO
                $bvpo_row = NetworkFee::select('*')
                    ->Where('code', '=', 'BVPO')
                    ->get();
                $bvcv = (($bvcv_row[0]->amount) / 100) * $bv_total;
                $bv_nett = $bv_total - $bvcv;
                $sbv = (($ref_fee_row[0]->sbv) / 100) * $bv_nett;
                //package activation type
                $package_activation_row = Activation::select('type', 'bv_min', 'bv_max')
                    ->Where('id', '=', $fee_upgrade_type_id)
                    ->get();
                //get sbv ref 1
                $ref1_row = Member::find($member->ref_id);
                $ref1_fee_point_sale = 0;
                $ref1_fee_point_upgrade = 0;
                $ref1_flush_out = 0;
                if (!empty($ref1_row) && $ref1_row->ref_id > 0) {
                    $rsbv_g1 = (($ref_fee_row[0]->rsbv_g1) / 100) * $sbv;
                    $ref1_fee_point_sale = $rsbv_g1;
                    //package_activation_type ref 1
                    $ref1_activation_row = Activation::select('type', 'bv_min', 'bv_max')
                        ->Where('id', '=', $ref1_row->activation_type_id)
                        ->get();
                    //set ref 1 fee
                    $ref1_fee_row = NetworkFee::select('*')
                        ->Where('type', '=', 'activation')
                        ->Where('activation_type_id', '=', $ref1_row->activation_type_id)
                        ->get();
                    //if ref 1 buseness
                    if ($ref1_activation_row[0]->type == 'business' && ($ref1_activation_row[0]->bv_min < $package_activation_row[0]->bv_min)) {
                        $bv_total_max = $ref1_activation_row[0]->bv_max * $bvpo_row[0]->amount;
                        $bvcv_max = (($bvcv_row[0]->amount) / 100) * $bv_total_max;
                        $bv_nett_max = $bv_total_max - $bvcv_max;
                        $sbv_max = (($ref1_fee_row[0]->sbv) / 100) * $bv_nett_max;
                        $ref1_fee_point_sale = (($ref1_fee_row[0]->rsbv_g1 / 100)) * $sbv_max;
                        $ref1_flush_out = $rsbv_g1 - $ref1_fee_point_sale;
                    }
                    //if ref 1 user
                    if ($ref1_activation_row[0]->type == 'user' && ($ref1_activation_row[0]->bv_min < $package_activation_row[0]->bv_min)) {
                        $bv_total_max = $ref1_activation_row[0]->bv_max * $bvpo_row[0]->amount;
                        $bvcv_max = (($bvcv_row[0]->amount) / 100) * $bv_total_max;
                        $bv_nett_max = $bv_total_max - $bvcv_max;
                        $sbv_max = (($ref1_fee_row[0]->sbv) / 100) * $bv_nett_max;
                        $ref1_fee_point_sale = (($ref1_fee_row[0]->rsbv_g1) / 100) * $sbv_max;
                        $ref1_fee_point_upgrade = $rsbv_g1 - $ref1_fee_point_sale;
                    }}
                //get sbv ref 2
                $ref2_row = Member::find($ref1_row->ref_id);
                $ref2_fee_point_sale = 0;
                $ref2_fee_point_upgrade = 0;
                if (!empty($ref2_row) && $ref2_row->ref_id > 0) {
                    $rsbv_g2 = (($ref_fee_row[0]->rsbv_g2) / 100) * $sbv;
                    $ref2_fee_point_sale = $rsbv_g2;
                    //package_activation_typ ref2
                    $ref2_activation_row = Activation::select('type', 'bv_min', 'bv_max')
                        ->Where('id', '=', $ref2_row->activation_type_id)
                        ->get();
                    //set ref 2 fee
                    $ref2_fee_row = NetworkFee::select('*')
                        ->Where('type', '=', 'activation')
                        ->Where('activation_type_id', '=', $ref2_row->activation_type_id)
                        ->get();
                    //if ref 2 buseness
                    if ($ref2_activation_row[0]->type == 'business' && ($ref2_activation_row[0]->bv_min < $package_activation_row[0]->bv_min)) {
                        $bv_total_max = $ref2_activation_row[0]->bv_max * $bvpo_row[0]->amount;
                        $bvcv_max = (($bvcv_row[0]->amount) / 100) * $bv_total_max;
                        $bv_nett_max = $bv_total_max - $bvcv_max;
                        $sbv_max = (($ref2_fee_row[0]->sbv) / 100) * $bv_nett_max;
                        $ref2_fee_point_sale = (($ref2_fee_row[0]->rsbv_g2) / 100) * $sbv_max;
                        $ref1_flush_out = 0;
                    }
                    //if ref 2 user
                    if ($ref2_activation_row[0]->type == 'user' && ($ref2_activation_row[0]->bv_min < $package_activation_row[0]->bv_min)) {
                        $bv_total_max = $ref2_activation_row[0]->bv_max * $bvpo_row[0]->amount;
                        $bvcv_max = (($bvcv_row[0]->amount) / 100) * $bv_total_max;
                        $bv_nett_max = $bv_total_max - $bvcv_max;
                        $sbv_max = (($ref2_fee_row[0]->sbv) / 100) * $bv_nett_max;
                        $ref2_fee_point_sale = (($ref2_fee_row[0]->rsbv_g2) / 100) * $sbv_max;
                        $ref2_fee_point_upgrade = $rsbv_g2 - $ref2_fee_point_sale;
                        $ref1_flush_out = 0;
                    }
                }

                //set order
                $warehouses_id = 1;
                $last_code = $this->get_last_code('order-agent');
                $order_code = acc_code_generate($last_code, 8, 3);
                $data = array('memo' => $memo, 'total' => $total, 'type' => 'activation_member', 'status' => 'pending', 'ledgers_id' => $ledger_id, 'customers_id' => $request->input('id'), 'agents_id' => $request->input('agents_id'), 'payment_type' => 'point', 'code' => $order_code, 'register' => $register, 'bv_activation_amount' => $bv_nett, 'customers_activation_id' => $request->input('id'));
                $order = Order::create($data);
                //set order products
                $order->products()->attach($request->input('package_id'), ['quantity' => 1, 'price' => $total, 'cogs' => $cogs_total]);
                //set order order details (inventory stock)
                $package_items = Package::with('products')
                    ->where('id', $request->input('package_id'))
                    ->get();
                $package_items = json_decode($package_items, false);
                $package_items = $package_items[0]->products;
                //loop items
                foreach ($package_items as $key => $value) {
                    $order->productdetails()->attach($value->id, ['quantity' => $value->pivot->quantity, 'type' => 'D', 'status' => 'onhold', 'warehouses_id' => $warehouses_id, 'owner' => $member->id]);
                    $order->productdetails()->attach($value->id, ['quantity' => $value->pivot->quantity, 'type' => 'C', 'status' => 'onhold', 'warehouses_id' => $warehouses_id, 'owner' => $request->input('agents_id')]);
                }

                /*update member */
                $parent_id = $this->set_parent($member->ref_id);
                $activation_at = date('Y-m-d H:i:s');
                //$member->parent_id = $parent_id;
                //$member->activation_at = $activation_at;
                //$member->status = 'active';
                $member->activation_type_id = $package_upgrade_type_id;
                $member->save();
                /*set order*/
                //set def
                $referal_id = $request->input('id');
                $agents_id = $request->input('agents_id');
                $warehouses_id = 1;
                $com_row = Member::select('*')
                    ->where('def', '=', '1')
                    ->get();
                $com_id = $com_row[0]->id;

                //PAIRING
                $fee_pairing = $this->pairing($order->id, $member->ref_id);

                //get profit
                $bvcv = (($bvcv_row[0]->amount) / 100) * $bv_total;
                $bv_nett = $bv_total - $bvcv;
                $profit_com = $bv_nett - $cba1 - $cba2 - $ref1_fee_point_sale - $ref1_fee_point_upgrade - $ref2_fee_point_sale - $ref2_fee_point_upgrade - $fee_pairing - $ref1_flush_out;

                //set account
                $acc_points = '67'; //utang poin
                $acc_res_cashback = '70';
                $acc_profit = '71';
                $reserve_amount = $bv_nett - $cba1;
                $points_amount = $reserve_amount - $profit_com;
                $profit_type='C';
                if($profit_com<0){
                    $acc_profit = '70';
                    $profit_type='D';
                    $profit_com=$profit_com * -1;
                }
                $accounts = array($acc_points, $acc_res_cashback, $acc_profit);
                $amounts = array($points_amount, $reserve_amount, $profit_com);
                $types = array('C', 'D', $profit_type);
                //ledger entries
                for ($account = 0; $account < count($accounts); $account++) {
                    if ($accounts[$account] != '') {
                        $ledger->accounts()->attach($accounts[$account], ['entry_type' => $types[$account], 'amount' => $amounts[$account]]);
                    }
                }

                //set trf points from member to Usadha Bhakti (pending points)
                $order->points()->attach($points_id, ['amount' => $total, 'type' => 'D', 'status' => 'onhand', 'memo' => 'Penambahan Poin dari (Pending Order) ' . $memo, 'customers_id' => $com_id]);
                $order->points()->attach($points_id, ['amount' => $total, 'type' => 'C', 'status' => 'onhand', 'memo' => 'Pemotongan Poin dari ' . $memo, 'customers_id' => $referal_id]);

                //set trf points cashback agent
                $order->points()->attach($points_id, ['amount' => $cba2, 'type' => 'D', 'status' => 'onhold', 'memo' => 'Penambahan Poin (Cashback Agen 02) dari ' . $memo, 'customers_id' => $agents_id]);
                //set trf points from member to agent (onhold)
                $order->points()->attach($points_id, ['amount' => $total, 'type' => 'D', 'status' => 'onhold', 'memo' => 'Penambahan Poin dari (Penjualan Paket) ' . $memo, 'customers_id' => $agents_id]);

                //set ref1 fee
                //point sale
                if ($ref1_fee_point_sale > 0) {
                    $order->points()->attach($points_id, ['amount' => $ref1_fee_point_sale, 'type' => 'D', 'status' => 'onhold', 'memo' => 'Poin Komisi (Refferal) dari ' . $memo, 'customers_id' => $member->ref_id]);
                }
                //point upgrade
                if ($ref1_fee_point_upgrade > 0) {
                    $order->points()->attach($points_upg_id, ['amount' => $ref1_fee_point_upgrade, 'type' => 'D', 'status' => 'onhold', 'memo' => 'Poin (Upgrade) Komisi (Refferal) dari ' . $memo, 'customers_id' => $member->ref_id]);
                }

                //set ref2 fee
                //point sale
                if ($ref2_fee_point_sale > 0) {
                    $order->points()->attach($points_id, ['amount' => $ref2_fee_point_sale, 'type' => 'D', 'status' => 'onhold', 'memo' => 'Poin Komisi (Refferal) dari ' . $memo, 'customers_id' => $ref2_row->id]);
                }
                //point upgrade
                if ($ref2_fee_point_upgrade > 0) {
                    $order->points()->attach($points_upg_id, ['amount' => $ref2_fee_point_upgrade, 'type' => 'D', 'status' => 'onhold', 'memo' => 'Poin (Upgrade) Komisi (Refferal) dari ' . $memo, 'customers_id' => $ref2_row->id]);
                }
                //point flush out
                if ($ref1_flush_out > 0) {
                    $order->points()->attach($points_id, ['amount' => $ref1_flush_out, 'type' => 'D', 'status' => 'onhold', 'memo' => 'Poin Komisi (Flush Out) dari ' . $memo, 'customers_id' => $ref2_row->id]);
                }

                //push notif to agent
                $user_os = CustomerApi::find($agents_id);
                $id_onesignal = $user_os->id_onesignal;
                $memo = 'Order Masuk dari ' . $memo;
                $register = date("Y-m-d");
                //store to logs_notif
                $data = ['register' => $register, 'customers_id' => $agents_id, 'memo' => $memo];
                $logs = LogNotif::create($data);
                //push notif
                if (!empty($id_onesignal)) {
                    OneSignal::sendNotificationToUser(
                        $memo,
                        $id_onesignal,
                        $url = null,
                        $data = null,
                        $buttons = null,
                        $schedule = null
                    );}
                $member_upg = CustomerApi::with('activations')->where('id', $member->id)->first();
                $member_upg->ref_link = "https://admin.belogherbal.com/member?ref=" . Hashids::encode($member->id);
                return response()->json([
                    'success' => true,
                    'message' => 'Aktivasi Member Berhasil!',
                    'data' => $member_upg,
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Poin atau Stok Barang Tidak Mencukupi! Poin Balance: ' . $points_balance . " Total package: " . $total . " Stok Agent: " . $stock_balance,
                ], 401);
            }
        }

    }

    public function activate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required',
            'package_id' => 'required',
            'agents_id' => 'required',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors(),
            ], 401);
        } else {
            //set member
            $member = Member::find($request->input('id'));
            //get point member
            $points_id = 1;
            $points_upg_id = 2;
            $points_debit = OrderPoint::where('customers_id', '=', $request->input('id'))
                ->where('type', '=', 'D')
                ->where('status', '=', 'onhand')
                ->sum('amount');
            $points_credit = OrderPoint::where('customers_id', '=', $request->input('id'))
                ->where('type', '=', 'C')
                ->where('status', '=', 'onhand')
                ->sum('amount');
            $points_balance = $points_debit - $points_credit;

            //get package price & cogs
            $package = Product::select('price', 'cogs', 'bv', 'activation_type_id')
                ->where('id', '=', $request->input('package_id'))
                ->get();
            $package = json_decode($package, false);
            $cogs_total = $package[0]->cogs;
            $bv_total = $package[0]->bv;
            $total = $package[0]->price;
            $package_activation_type_id = $package[0]->activation_type_id;
            $profit = $total - $cogs_total;

            //get stock agent, loop package
            $stock_status = 'true';
            $package_items = Package::with('products')
                ->where('id', $request->input('package_id'))
                ->get();
            $package_items = json_decode($package_items, false);
            $package_items = $package_items[0]->products;
            //loop items
            foreach ($package_items as $key => $value) {
                //get qty package product & compare sum stock
                $stock_debit = OrderDetails::where('owner', '=', $request->input('agents_id'))
                    ->where('type', '=', 'D')
                    ->where('status', '=', 'onhand')
                    ->where('products_id', $value->id)
                    ->sum('quantity');
                $stock_credit = OrderDetails::where('owner', '=', $request->input('agents_id'))
                    ->where('type', '=', 'C')
                    ->where('status', '=', 'onhand')
                    ->where('products_id', $value->id)
                    ->sum('quantity');
                $stock_balance = $stock_debit - $stock_credit;
                if ($stock_balance < $value->pivot->quantity) {
                    $stock_status = 'false';
                }
            }

            //compare total to point belanja
            if ($points_balance >= $total && $member->status == 'pending') {
                //init
                $register = date("Y-m-d");
                $memo = 'Aktivasi Member ' . $member->code . "-" . $member->name;
                /* proceed ledger */
                $data = ['register' => $register, 'title' => $memo, 'memo' => $memo, 'status' => 'pending'];
                $ledger = Ledger::create($data);
                $ledger_id = $ledger->id;
                //set ledger entry arr
                //CBA 1
                $networkfee1_row = NetworkFee::select('*')
                    ->Where('code', '=', 'CBA01')
                    ->get();
                $cba1 = (($networkfee1_row[0]->amount) / 100) * $total;
                //CBA 2
                $networkfee2_row = NetworkFee::select('*')
                    ->Where('code', '=', 'CBA02')
                    ->get();
                $cba2 = (($networkfee2_row[0]->amount) / 100) * $total;
                //check type package activation
                $package_obj = Package::find($request->input('package_id'));
                //set ref fee
                $ref_fee_row = NetworkFee::select('*')
                    ->Where('type', '=', 'activation')
                    ->Where('activation_type_id', '=', $package_obj->activation_type_id)
                    ->get();
                //BVCV
                $bvcv_row = NetworkFee::select('*')
                    ->Where('code', '=', 'BVCV')
                    ->get();
                //BVPO
                $bvpo_row = NetworkFee::select('*')
                    ->Where('code', '=', 'BVPO')
                    ->get();
                $bvcv = (($bvcv_row[0]->amount) / 100) * $bv_total;
                $bv_nett = $bv_total - $bvcv;
                $sbv = (($ref_fee_row[0]->sbv) / 100) * $bv_nett;
                //package activation type
                $package_activation_row = Activation::select('type', 'bv_min', 'bv_max')
                    ->Where('id', '=', $package_activation_type_id)
                    ->get();
                //get sbv ref 1
                $ref1_row = Member::find($member->ref_id);
                $ref1_fee_point_sale = 0;
                $ref1_fee_point_upgrade = 0;
                $ref1_flush_out = 0;
                if (!empty($ref1_row) && $ref1_row->ref_id > 0) {
                    $rsbv_g1 = (($ref_fee_row[0]->rsbv_g1) / 100) * $sbv;
                    $ref1_fee_point_sale = $rsbv_g1;
                    //package_activation_type ref 1
                    $ref1_activation_row = Activation::select('type', 'bv_min', 'bv_max')
                        ->Where('id', '=', $ref1_row->activation_type_id)
                        ->get();
                    //set ref 1 fee
                    $ref1_fee_row = NetworkFee::select('*')
                        ->Where('type', '=', 'activation')
                        ->Where('activation_type_id', '=', $ref1_row->activation_type_id)
                        ->get();
                    //if ref 1 buseness
                    if ($ref1_activation_row[0]->type == 'business' && ($ref1_activation_row[0]->bv_min < $package_activation_row[0]->bv_min)) {
                        $bv_total_max = $ref1_activation_row[0]->bv_max * $bvpo_row[0]->amount;
                        $bvcv_max = (($bvcv_row[0]->amount) / 100) * $bv_total_max;
                        $bv_nett_max = $bv_total_max - $bvcv_max;
                        $sbv_max = (($ref1_fee_row[0]->sbv) / 100) * $bv_nett_max;
                        $ref1_fee_point_sale = (($ref1_fee_row[0]->rsbv_g1 / 100)) * $sbv_max;
                        $ref1_flush_out = $rsbv_g1 - $ref1_fee_point_sale;
                    }
                    //if ref 1 user
                    if ($ref1_activation_row[0]->type == 'user' && ($ref1_activation_row[0]->bv_min < $package_activation_row[0]->bv_min)) {
                        $bv_total_max = $ref1_activation_row[0]->bv_max * $bvpo_row[0]->amount;
                        $bvcv_max = (($bvcv_row[0]->amount) / 100) * $bv_total_max;
                        $bv_nett_max = $bv_total_max - $bvcv_max;
                        $sbv_max = (($ref1_fee_row[0]->sbv) / 100) * $bv_nett_max;
                        $ref1_fee_point_sale = (($ref1_fee_row[0]->rsbv_g1) / 100) * $sbv_max;
                        $ref1_fee_point_upgrade = $rsbv_g1 - $ref1_fee_point_sale;
                    }}
                //get sbv ref 2
                $ref2_row = Member::find($ref1_row->ref_id);
                $ref2_fee_point_sale = 0;
                $ref2_fee_point_upgrade = 0;
                if (!empty($ref2_row) && $ref2_row->ref_id > 0) {
                    $rsbv_g2 = (($ref_fee_row[0]->rsbv_g2) / 100) * $sbv;
                    $ref2_fee_point_sale = $rsbv_g2;
                    //package_activation_typ ref2
                    $ref2_activation_row = Activation::select('type', 'bv_min', 'bv_max')
                        ->Where('id', '=', $ref2_row->activation_type_id)
                        ->get();
                    //set ref 2 fee
                    $ref2_fee_row = NetworkFee::select('*')
                        ->Where('type', '=', 'activation')
                        ->Where('activation_type_id', '=', $ref2_row->activation_type_id)
                        ->get();
                    //if ref 2 buseness
                    if ($ref2_activation_row[0]->type == 'business' && ($ref2_activation_row[0]->bv_min < $package_activation_row[0]->bv_min)) {
                        $bv_total_max = $ref2_activation_row[0]->bv_max * $bvpo_row[0]->amount;
                        $bvcv_max = (($bvcv_row[0]->amount) / 100) * $bv_total_max;
                        $bv_nett_max = $bv_total_max - $bvcv_max;
                        $sbv_max = (($ref2_fee_row[0]->sbv) / 100) * $bv_nett_max;
                        $ref2_fee_point_sale = (($ref2_fee_row[0]->rsbv_g2) / 100) * $sbv_max;
                        $ref1_flush_out = 0;
                    }
                    //if ref 2 user
                    if ($ref2_activation_row[0]->type == 'user' && ($ref2_activation_row[0]->bv_min < $package_activation_row[0]->bv_min)) {
                        $bv_total_max = $ref2_activation_row[0]->bv_max * $bvpo_row[0]->amount;
                        $bvcv_max = (($bvcv_row[0]->amount) / 100) * $bv_total_max;
                        $bv_nett_max = $bv_total_max - $bvcv_max;
                        $sbv_max = (($ref2_fee_row[0]->sbv) / 100) * $bv_nett_max;
                        $ref2_fee_point_sale = (($ref2_fee_row[0]->rsbv_g2) / 100) * $sbv_max;
                        $ref2_fee_point_upgrade = $rsbv_g2 - $ref2_fee_point_sale;
                        $ref1_flush_out = 0;
                    }
                }

                //set order
                $warehouses_id = 1;
                $last_code = $this->get_last_code('order-agent');
                $order_code = acc_code_generate($last_code, 8, 3);
                $data = array('memo' => $memo, 'total' => $total, 'type' => 'activation_member', 'status' => 'pending', 'ledgers_id' => $ledger_id, 'customers_id' => $request->input('id'), 'agents_id' => $request->input('agents_id'), 'payment_type' => 'point', 'code' => $order_code, 'register' => $register, 'bv_activation_amount' => $bv_nett, 'customers_activation_id' => $request->input('id'));
                $order = Order::create($data);
                //set order products
                $order->products()->attach($request->input('package_id'), ['quantity' => 1, 'price' => $total, 'cogs' => $cogs_total]);
                //set order order details (inventory stock)
                $package_items = Package::with('products')
                    ->where('id', $request->input('package_id'))
                    ->get();
                $package_items = json_decode($package_items, false);
                $package_items = $package_items[0]->products;
                //loop items
                foreach ($package_items as $key => $value) {
                    $order->productdetails()->attach($value->id, ['quantity' => $value->pivot->quantity, 'type' => 'D', 'status' => 'onhold', 'warehouses_id' => $warehouses_id, 'owner' => $member->id]);
                    $order->productdetails()->attach($value->id, ['quantity' => $value->pivot->quantity, 'type' => 'C', 'status' => 'onhold', 'warehouses_id' => $warehouses_id, 'owner' => $request->input('agents_id')]);
                }

                /*update member */
                $parent_id = $this->set_parent($member->ref_id);
                $activation_at = date('Y-m-d H:i:s');
                $member->parent_id = $parent_id;
                $member->activation_at = $activation_at;
                $member->status = 'active';
                $member->activation_type_id = $package_activation_type_id;
                $member->save();
                /*set order*/
                //set def
                $referal_id = $request->input('id');
                $agents_id = $request->input('agents_id');
                $warehouses_id = 1;
                $com_row = Member::select('*')
                    ->where('def', '=', '1')
                    ->get();
                $com_id = $com_row[0]->id;

                //PAIRING
                $fee_pairing = $this->pairing($order->id, $member->ref_id);

                //get profit
                $bvcv = (($bvcv_row[0]->amount) / 100) * $bv_total;
                $bv_nett = $bv_total - $bvcv;
                $profit_com = $bv_nett - $cba1 - $cba2 - $ref1_fee_point_sale - $ref1_fee_point_upgrade - $ref2_fee_point_sale - $ref2_fee_point_upgrade - $fee_pairing - $ref1_flush_out;

                //set account
                $acc_points = '67'; //utang poin
                $acc_res_cashback = '70';
                $acc_profit = '71';
                $reserve_amount = $bv_nett - $cba1;
                $points_amount = $reserve_amount - $profit_com;
                $profit_type='C';
                if($profit_com<0){
                    $acc_profit = '70';
                    $profit_type='D';
                    $profit_com=$profit_com * -1;
                }
                $accounts = array($acc_points, $acc_res_cashback, $acc_profit);
                $amounts = array($points_amount, $reserve_amount, $profit_com);
                $types = array('C', 'D', $profit_type);
                //ledger entries
                for ($account = 0; $account < count($accounts); $account++) {
                    if ($accounts[$account] != '') {
                        $ledger->accounts()->attach($accounts[$account], ['entry_type' => $types[$account], 'amount' => $amounts[$account]]);
                    }
                }

                //set trf points from member to Usadha Bhakti (pending points)
                $order->points()->attach($points_id, ['amount' => $total, 'type' => 'D', 'status' => 'onhand', 'memo' => 'Penambahan Poin dari (Pending Order) ' . $memo, 'customers_id' => $com_id]);
                $order->points()->attach($points_id, ['amount' => $total, 'type' => 'C', 'status' => 'onhand', 'memo' => 'Pemotongan Poin dari ' . $memo, 'customers_id' => $referal_id]);

                //set trf points cashback agent
                $order->points()->attach($points_id, ['amount' => $cba2, 'type' => 'D', 'status' => 'onhold', 'memo' => 'Penambahan Poin (Cashback Agen 02) dari ' . $memo, 'customers_id' => $agents_id]);
                //set trf points from member to agent (onhold)
                $order->points()->attach($points_id, ['amount' => $total, 'type' => 'D', 'status' => 'onhold', 'memo' => 'Penambahan Poin dari (Penjualan Paket) ' . $memo, 'customers_id' => $agents_id]);

                //set ref1 fee
                //point sale
                if ($ref1_fee_point_sale > 0) {
                    $order->points()->attach($points_id, ['amount' => $ref1_fee_point_sale, 'type' => 'D', 'status' => 'onhold', 'memo' => 'Poin Komisi (Refferal) dari ' . $memo, 'customers_id' => $member->ref_id]);
                }
                //point upgrade
                if ($ref1_fee_point_upgrade > 0) {
                    $order->points()->attach($points_upg_id, ['amount' => $ref1_fee_point_upgrade, 'type' => 'D', 'status' => 'onhold', 'memo' => 'Poin (Upgrade) Komisi (Refferal) dari ' . $memo, 'customers_id' => $member->ref_id]);
                }

                //set ref2 fee
                //point sale
                if ($ref2_fee_point_sale > 0) {
                    $order->points()->attach($points_id, ['amount' => $ref2_fee_point_sale, 'type' => 'D', 'status' => 'onhold', 'memo' => 'Poin Komisi (Refferal) dari ' . $memo, 'customers_id' => $ref2_row->id]);
                }
                //point upgrade
                if ($ref2_fee_point_upgrade > 0) {
                    $order->points()->attach($points_upg_id, ['amount' => $ref2_fee_point_upgrade, 'type' => 'D', 'status' => 'onhold', 'memo' => 'Poin (Upgrade) Komisi (Refferal) dari ' . $memo, 'customers_id' => $ref2_row->id]);
                }
                //point flush out
                if ($ref1_flush_out > 0) {
                    $order->points()->attach($points_id, ['amount' => $ref1_flush_out, 'type' => 'D', 'status' => 'onhold', 'memo' => 'Poin Komisi (Flush Out) dari ' . $memo, 'customers_id' => $ref2_row->id]);
                }

                //push notif to agent
                $user_os = CustomerApi::find($agents_id);
                $id_onesignal = $user_os->id_onesignal;
                $memo = 'Order Masuk dari ' . $memo;
                $register = date("Y-m-d");
                //store to logs_notif
                $data = ['register' => $register, 'customers_id' => $agents_id, 'memo' => $memo];
                $logs = LogNotif::create($data);
                //push notif
                if (!empty($id_onesignal)) {
                    OneSignal::sendNotificationToUser(
                        $memo,
                        $id_onesignal,
                        $url = null,
                        $data = null,
                        $buttons = null,
                        $schedule = null
                    );}

                return response()->json([
                    'success' => true,
                    'message' => 'Aktivasi Member Berhasil!',
                    'data' => $member,
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Poin atau Stok Barang Tidak Mencukupi! atau Member sudah aktif! Poin Balance: ' . $points_balance . " Total package: " . $total . " Stok Agent: " . $stock_balance . " Member Satus: " . $member->status,
                ], 401);
            }
        }

    }

    public function activateAgent(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required',
            'package_id' => 'required',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors(),
            ], 401);
        } else {
            //set member
            $member = Member::find($request->input('id'));
            //get point member
            $points_id = 1;
            $points_debit = OrderPoint::where('customers_id', '=', $request->input('id'))
                ->where('type', '=', 'D')
                ->sum('amount');
            $points_credit = OrderPoint::where('customers_id', '=', $request->input('id'))
                ->where('type', '=', 'C')
                ->sum('amount');
            $points_balance = $points_debit - $points_credit;

            //get package price & cogs
            $package = Product::select('price', 'cogs', 'bv')
                ->where('id', '=', $request->input('package_id'))
                ->get();
            $package = json_decode($package, false);
            $cogs_total = $package[0]->cogs;
            $bv_total = $package[0]->bv;
            $total = $package[0]->price;
            $profit = $total - $cogs_total;

            //compare total to point belanja
            if ($points_balance >= $total && $member->status == 'pending') {
                //init
                $register = date("Y-m-d");
                $memo = 'Aktivasi Agen ' . $member->code . "-" . $member->name;
                /* proceed ledger */
                $data = ['register' => $register, 'title' => $memo, 'memo' => $memo];
                $ledger = Ledger::create($data);
                $ledger_id = $ledger->id;
                //set ledger entry arr
                $acc_inv_stock = '20';
                $acc_sale = '44';
                $acc_exp_cogs = '45';
                $acc_points = '67'; //utang poin
                $total_pay = $total;
                $accounts = array($acc_inv_stock, $acc_exp_cogs, $acc_sale);
                $amounts = array($cogs_total, $cogs_total, $total);
                $types = array('C', 'D', 'C');
                //if agent get cashback
                $customer_row = CustomerApi::select('*')
                    ->Where('id', '=', $request->input('id'))
                    ->get();
                if ($customer_row[0]->type == 'agent') {
                    //get cashback 01
                    $acc_disc = 68;
                    $acc_res_cashback = 70;
                    //CBA 1
                    $networkfee_row = NetworkFee::select('*')
                        ->Where('code', '=', 'CBA01')
                        ->get();
                    //BVCV
                    $bvcv_row = NetworkFee::select('*')
                        ->Where('code', '=', 'BVCV')
                        ->get();
                    $cba1 = (($networkfee_row[0]->amount) / 100) * $total;
                    $bvcv = (($bvcv_row[0]->amount) / 100) * $bv_total;
                    $bv_nett = $bv_total - $bvcv;
                    $round_profit = $total - $cogs_total - $bv_total;
                    $profit = $bvcv + $round_profit; // (set to ledger profit)
                    $amount_disc = $bv_nett; // (potongan penjualan)
                    $amount_res_cashback = $amount_disc - $cba1; //(reserve/cadangan)
                    $total_pay = $total - $cba1;
                    //$acc_points = '67';
                    //push array jurnal
                    array_push($accounts, $acc_disc, $acc_res_cashback, $acc_points);
                    array_push($amounts, $amount_disc, $amount_res_cashback, $total_pay);
                    array_push($types, "D", "C", "D");
                }
                //ledger entries
                for ($account = 0; $account < count($accounts); $account++) {
                    if ($accounts[$account] != '') {
                        $ledger->accounts()->attach($accounts[$account], ['entry_type' => $types[$account], 'amount' => $amounts[$account]]);
                    }
                }

                /*update member */
                $member->status = 'active';
                $member->save();
                /* set order, order products, order details (inventory stock), order points */
                //set def
                $ref_def_id = CustomerApi::select('id')
                    ->Where('def', '=', '1')
                    ->get();
                $owner_def = $ref_def_id[0]->id;
                $customers_id = $request->input('id');
                $warehouses_id = 1;
                //set order
                $last_code = $this->get_last_code('order');
                $order_code = acc_code_generate($last_code, 8, 3);
                $data = array('memo' => $memo, 'total' => $total, 'type' => 'sale', 'status' => 'approved', 'ledgers_id' => $ledger_id, 'customers_id' => $customers_id, 'payment_type' => 'point', 'code' => $order_code, 'register' => $register);
                $order = Order::create($data);
                //set order products
                $order->products()->attach($request->input('package_id'), ['quantity' => 1, 'price' => $total]);
                //set order order details (inventory stock)
                $package_items = Package::with('products')
                    ->where('id', $request->input('package_id'))
                    ->get();
                $package_items = json_decode($package_items, false);
                $package_items = $package_items[0]->products;
                //loop items
                foreach ($package_items as $key => $value) {
                    $order->productdetails()->attach($value->id, ['quantity' => $value->pivot->quantity, 'type' => 'D', 'status' => 'onhand', 'warehouses_id' => $warehouses_id, 'owner' => $customers_id]);
                    $order->productdetails()->attach($value->id, ['quantity' => $value->pivot->quantity, 'type' => 'C', 'status' => 'onhand', 'warehouses_id' => $warehouses_id, 'owner' => $owner_def]);
                }
                //set trf points from customer to Usdha Bhakti
                // $order->points()->attach($points_id, ['amount' => $total_pay, 'type' => 'D', 'status' => 'onhand', 'memo' => 'Penambahan Poin dari ' . $memo, 'customers_id' => $owner_def]);
                $order->points()->attach($points_id, ['amount' => $total_pay, 'type' => 'C', 'status' => 'onhand', 'memo' => 'Pemotongan Poin dari ' . $memo, 'customers_id' => $customers_id]);

                return response()->json([
                    'success' => true,
                    'message' => 'Aktivasi Agen Berhasil!',
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Poin atau Stok Barang Tidak Mencukupi! atau Member sudah aktif! Poin Balance: ' . $points_balance . " Total package: " . $total . " Agen Satus: " . $member->status,
                ], 401);
            }
        }

    }

    public function logout(Request $res)
    {
        if (Auth::user()) {
            $user = Auth::user()->token();
            $user->revoke();

            return response()->json([
                'success' => true,
                'message' => 'Logout successfully',
            ]);
        } else {
            return response()->json([
                'success' => false,
                'message' => 'Unable to Logout',
            ]);
        }
    }

    public function agents()
    {
        $agents = CustomerApi::select('*')
            ->where('type', 'agent')
            ->get();

        return $agents;
    }

    public function agentsOpen()
    {
        $agents = CustomerApi::select('*')
            ->where('type', 'agent')
            ->get();

        // return $agents;

        if (is_null($agents)) {
            $message = 'Data not found.';
            $status = false;
            return response()->json([
                'status' => $status,
                'message' => $message,
            ]);
        } else {
            $message = 'Data retrieved successfully.';
            $status = true;
            return response()->json([
                'status' => $status,
                'message' => $message,
                'data' => $agents,
            ]);
        }
    }

    public function agentshow($id)
    {
        $agent = CustomerApi::find($id);

        //Check if agent found or not.
        if (is_null($agent)) {
            $message = 'Product not found.';
            $status = false;
            return response()->json([
                'status' => $status,
                'message' => $message,
            ]);
        }
        $message = 'Product retrieved successfully.';
        $status = true;
        return response()->json([
            'status' => $status,
            'message' => $message,
            'data' => $agent,
        ]);
    }
    public function location()
    {
        try {
            $province = Province::all();
            $city = City::all();

            return response()->json([
                'code'=> 200,
                'message' => 'success',
                'province' => $province,
                'city' => $city 
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'code' => 404,
                'message' => 'failed',
                'data' => $th
            ]);
        }
    }

}