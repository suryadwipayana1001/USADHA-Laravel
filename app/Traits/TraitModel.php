<?php

namespace App\Traits;

use App\Account;
use App\AccountsGroup;
use App\Activation;
use App\Asset;
use App\Capital;
use App\Customer;
use App\NetworkFee;
use App\Order;
use App\Pairing;
use App\Payreceivable;
use App\PayreceivableTrs;
use App\Production;
use Illuminate\Database\QueryException;

trait TraitModel
{
    public function auto_activation_type($ref_id, $parent_id)
    {
        //list member active
        $members_list = Customer::select('id', 'code', 'created_at')
            ->where('status', '=', 'active')
            ->orderBy('created_at', 'asc')
            ->get();
        foreach ($members_list as $key => $member) {
            //find order point & get amount with keyword 'Pemotongan Poin dari Aktivasi Member MBR00009%'
            $member_activation_row = Customer::select('amount', 'orders_id')
                ->where('memo', 'LIKE', 'Pemotongan Poin dari Aktivasi Member ' . $member->id . '%')
                ->first();
            $member_activation_amount = $member_activation_row->amount;
            //BVPO
            $bvpo_row = NetworkFee::select('*')
                ->Where('code', '=', 'BVPO')
                ->get();
            //get activation bv max
            $package_activation_user = Activation::select('type', 'bv_min', 'bv_max')
                ->Where('id', '=', '1')
                ->get();
            $package_activation_silver = Activation::select('type', 'bv_min', 'bv_max')
                ->Where('id', '=', '2')
                ->get();
            $package_activation_gold = Activation::select('type', 'bv_min', 'bv_max')
                ->Where('id', '=', '3')
                ->get();
            $package_activation_platinum = Activation::select('type', 'bv_min', 'bv_max')
                ->Where('id', '=', '4')
                ->get();
            //conditional amount if... range set activation_type_id
            if ($member_activation_amount > 0 && $member_activation_amount < 1000000) {
                $activation_type_id = 1;
                $bv_activation_amount = $package_activation_user[0]->bv_max * $bvpo_row[0]->amount;
            } else if ($member_activation_amount >= 1000000 && $member_activation_amount < 2000000) {
                $activation_type_id = 2;
                $bv_activation_amount = $package_activation_silver[0]->bv_max * $bvpo_row[0]->amount;
            } else if ($member_activation_amount >= 2000000 && $member_activation_amount < 5000000) {
                $activation_type_id = 3;
                $bv_activation_amount = $package_activation_gold[0]->bv_max * $bvpo_row[0]->amount;
            } else if ($member_activation_amount >= 5000000) {
                $activation_type_id = 4;
                $bv_activation_amount = $package_activation_platinum[0]->bv_max * $bvpo_row[0]->amount;
            } else {
                $activation_type_id = 1;
                $bv_activation_amount = $package_activation_user[0]->bv_max * $bvpo_row[0]->amount;
            }
            //set activation_at = created_at
            $activation_at = $member->created_at;
            //update member
            $member = Customer::find($member);
            $member->activation_type_id = $activation_type_id;
            $member->activation_at = $activation_at;
            $member->save();
            //update order
            $order = Order::find($member_activation_row->orders_id);
            $order->bv_activation_amount = $bv_activation_amount;
            $order->customers_activation_id = $activation_type_id;
        }
    }

    public function auto_parent($ref_id, $parent_id)
    {
        //list ref downline
        $downref_list = Customer::select('id')
            ->where('ref_id', $ref_id)
            ->where('status', '=', 'active')
            ->orderBy('created_at', 'asc')
            ->get();
        foreach ($downref_list as $key => $downref) {
            //update parent_id to -> $parent_id
            $member = Customer::find($downref);
            $member->parent_id = $parent_id;
            $member->save();
            //set next parent_id
            $parent_id = $downref;
            //recursive
            $this->auto_parent($downref, $downref);
        }
    }

    public function pairing($id_order, $ref1_id, $test = 0)
    {
        $ref1_row = Customer::find($ref1_id);
        //init
        $fee_out = 0;
        $ref2_row = Customer::find($ref1_row->ref_id);
        if (!empty($ref2_row) && $ref2_row->ref_id != 0 && $ref2_row->id > 0) {
            //get network fee pairing -> ref1 activation type
            $nf_rf2_pairing_row = NetworkFee::select('*')
                ->Where('type', '=', 'pairing')
                ->Where('activation_type_id', '=', $ref2_row->activation_type_id)
                ->get();
            $deep_level = $nf_rf2_pairing_row[0]->deep_level;
            if ($ref2_row->id > 0 && (!empty($ref2_row)) && ($ref2_row->ref_id > 0)) {
                // $dwn_arr = array();
                $dwn_arr = $this->downref_list($ref2_row->id, $deep_level);
                foreach ($dwn_arr as $downline) {
                    //get9 pairing_lev
                    //$fee_out .= "-".$id_order."-".$downline->id."-".$deep_level;
                    $fee_out += $this->pairing_lev($id_order, $downline->id, $deep_level, $test);
                    $deep_level--;
                }}
        }
        return $fee_out;
    }

    public function pairing_lev($id_order, $ref1_id, $deep_level, $test = 0)
    {
        //init
        $test_out = array();
        $fee_out = 0;
        if ($test == 0) {
            $order = Order::find($id_order);
        }
        $points_id = 1;
        $points_upg_id = 2;
        //BVPO
        $bvpo_row = NetworkFee::select('*')
            ->Where('code', '=', 'BVPO')
            ->get();
        //get ref2 activation type
        $ref1_row = Customer::find($ref1_id);
        $ref1_activation_row = Activation::find($ref1_row->activation_type_id);
        if (!empty($ref1_row) && $ref1_row->ref_id > 0) {
            $memo = $ref1_row->code . " - " . $ref1_row->name;
            $deep_lev = $deep_level;
            //get pairing ref1 balance
            $pairing_ref1_balance = $this->pairing_ref1_balance($ref1_id, $deep_lev);
            //get network fee pairing -> ref1 activation type
            $nf_rf1_pairing_row = NetworkFee::select('*')
                ->Where('type', '=', 'pairing')
                ->Where('activation_type_id', '=', $ref1_row->activation_type_id)
                ->get();
            //get min bv pairing -> ref1 activation type
            $min_bv_pairing = $nf_rf1_pairing_row[0]->bv_min_pairing * $bvpo_row[0]->amount;
            //if pairing ref1 balance >= min bv pairing -> process
            if ($pairing_ref1_balance >= $min_bv_pairing) {
                //set fee to ref1
                $ref1_amount = 0;
                $ref2_amount = 0;
                if ($test == 0 && $ref1_row->status == 'active') {
                    $ref1_fee_pairing = (($nf_rf1_pairing_row[0]->sbv) / 100) * $pairing_ref1_balance;
                    $fee_out += $ref1_fee_pairing;
                    $ref1_amount = $ref1_fee_pairing;
                    //hitung total bv_amount hari ini yang sudah di pairing di tbl pairing {bvarp_paired}
                    $reg_today = date('Y-m-d');
                    $daily_amount_paired = Pairing::where('ref1_id', '=', $ref1_id)
                        ->whereDate('register', '=', $reg_today)
                        ->sum('ref1_amount');
                    if ($daily_amount_paired <= $nf_rf1_pairing_row[0]->fee_day_max) {
                        $order->points()->attach($points_id, ['amount' => $ref1_fee_pairing, 'type' => 'D', 'status' => 'onhold', 'memo' => 'Poin Komisi (Pairing) dari group ' . $memo, 'customers_id' => $ref1_row->id]);
                    } else {
                        $ref1_amount = 0;
                    }
                    //set fee to ref2
                    $ref2_id = 0;
                    $ref2_row = Customer::find($ref1_row->ref_id);
                    $ref2_activation_row = Activation::find($ref2_row->activation_type_id);
                    if (!empty($ref2_row) && $ref2_row->ref_id > 0 && $test == 0 && $ref2_row->status == 'active') {
                        //get network fee pairing -> ref1 activation type
                        $nf_rf2_pairing_row = NetworkFee::select('*')
                            ->Where('type', '=', 'pairing')
                            ->Where('activation_type_id', '=', $ref2_row->activation_type_id)
                            ->get();
                        $ref2_id = $ref2_row->id;
                        $ref2_fee_pairing = (($nf_rf2_pairing_row[0]->sbv) / 100) * $pairing_ref1_balance;
                        $fee_out += $ref2_fee_pairing;
                        $ref2_amount = $ref2_fee_pairing;
                        //hitung total bv_amount hari ini yang sudah di pairing di tbl pairing {bvarp_paired}
                        $daily_amount_paired2 = Pairing::where('ref2_id', '=', $ref2_id)
                            ->whereDate('register', '=', $reg_today)
                            ->sum('ref2_amount');
                        if ($daily_amount_paired2 <= $nf_rf2_pairing_row[0]->fee_day_max) {
                            $order->points()->attach($points_id, ['amount' => $ref2_fee_pairing, 'type' => 'D', 'status' => 'onhold', 'memo' => 'Poin Komisi (Pairing) dari group ' . $memo, 'customers_id' => $ref2_row->id]);
                        } else {
                            $ref2_amount = 0;
                        }
                    }
                    //set matching
                    //find ref3
                    $ref3_row = Customer::find($ref2_row->ref_id);
                    $ref3_activation_row = Activation::find($ref3_row->activation_type_id);
                    if (!empty($ref3_row) && $ref3_row->ref_id > 0 && !$test && $ref3_row->status == 'active') {
                        //get network fee pairing -> ref3 activation type
                        $nf_rf3_pairing_row = NetworkFee::select('*')
                            ->Where('type', '=', 'matching')
                            ->Where('activation_type_id', '=', $ref3_row->activation_type_id)
                            ->get();
                        $ref3_fee_pairing = (($nf_rf3_pairing_row[0]->amount) / 100) * $ref1_fee_pairing;
                        //set fee to ref3
                        $fee_out += $ref3_fee_pairing;
                        $order->points()->attach($points_id, ['amount' => $ref3_fee_pairing, 'type' => 'D', 'status' => 'onhold', 'memo' => 'Poin Komisi (Matching) dari group ' . $memo, 'customers_id' => $ref3_row->id]);
                    }
                    //insert into tbl pairings
                    $register = date('Y-m-d H:i:s');
                    $data = ['register' => $register, 'ref1_id' => $ref1_row->id, 'ref2_id' => $ref2_id, 'bv_amount' => $pairing_ref1_balance, 'order_id' => $id_order, 'ref2_amount' => $ref2_amount, 'ref1_amount' => $ref1_amount];
                    $logs = Pairing::create($data);
                }
            }}

        return $fee_out;
    }

    public function pairing_ref1_balance($ref1_id, $deep_lev)
    {
        $arr_out = array();
        $bv_pairing = 0;
        //hitung total bv_amount yang sudah di pairing di tbl pairing {bvarp_paired}
        $bvarp_paired = Pairing::where('ref1_id', '=', $ref1_id)
            ->sum('bv_amount');
        //hitung selisih bv_amount yang sudah di pairing dengan total bv_amount activasi {bvarp_paired_balance}
        $bvarp = $this->pairing_ref1($ref1_id, $deep_lev);
        $bvarp_paired_balance = $bvarp - $bvarp_paired;
        //hitung selisih bv_amount yang sudah di pairing dengan total bv_amount group {bvarp_g_paired_balance}
        $bvarp_g = $this->pairing_group($ref1_id, $deep_lev);
        $bvarp_g_paired_balance = $bvarp_g - $bvarp_paired;
        //compare {bvarp_paired_balance} dengan {bvarp_g_paired_balance},
        if ($bvarp_g_paired_balance >= $bvarp_paired_balance) {
            $bv_pairing = $bvarp_paired_balance;
        } else {
            $bv_pairing = $bvarp_g_paired_balance;
        }
        $arr_out['bvarp_paired'] = $bvarp_paired;
        $arr_out['bvarp'] = $bvarp;
        $arr_out['bvarp_g'] = $bvarp_g;
        $arr_out['bv_pairing'] = $bv_pairing;
        //return $arr_out;
        return $bv_pairing;
    }

    public function pairing_ref1($ref1_id, $deep_lev, $inc_ref1 = 0)
    {
        $bv_activation_amount_total = 0;
        $ref1_row = Customer::find($ref1_id);
        $activation_row = Activation::find($ref1_row->activation_type_id);
        if ($inc_ref1 == 1 && $activation_row->type != "user") {
            //get ref1 bv activation
            $balance = Order::where('customers_activation_id', '=', $ref1_id)
                ->where('type', '=', 'activation_member')
                ->where('status', '!=', 'closed')
                ->sum('bv_activation_amount');
            $bv_activation_amount_total = $balance;
        }
        $downref_list = $this->downref_list($ref1_id, $deep_lev);
        //loop downref_list
        foreach ($downref_list as $downline) {
            $downline_row = Customer::find($downline->id);
            $downline_activation_row = Activation::find($downline_row->activation_type_id);
            if ($downline_activation_row->type != "user") {
                //get bv_activation_amount
                $balance = Order::where('customers_activation_id', '=', $downline->id)
                    ->where('type', '=', 'activation_member')
                    ->where('status', '!=', 'closed')
                    ->where('created_at', '>=', $ref1_row->activation_at)
                    ->sum('bv_activation_amount');
                $bv_activation_amount_total += $balance;
            }
        }
        return $bv_activation_amount_total;
    }

    public function pairing_group($parent_id, $deep_lev)
    {
        $bv_activation_amount_total = 0;
        $dwn_arr = array();
        $parent_row = Customer::find($parent_id);
        $ref_id = $parent_row->ref_id;
        $dwn_arr = $this->downline_list($ref_id, $parent_id, $dwn_arr, 1, 0, $deep_lev);
        foreach ($dwn_arr as $downline) {
            //get bv_activation_amount
            $balance = $this->pairing_ref1($downline, $deep_lev);
            $bv_activation_amount_total += $balance;
        }
        return $bv_activation_amount_total;

    }

    //semua downline yang di refrensikan langsung olehnya
    public function downref_list($ref_id, $deep_lev)
    {
        $downref_list = Customer::select('id')
            ->where('ref_id', $ref_id)
            ->where('status', '=', 'active')
            ->orderBy('activation_at', 'asc')
            ->skip(0)
            ->take($deep_lev)
            ->get();
        return $downref_list;
    }

    //semua downline semu yang di refrensikan parentnya (for pairing group)
    public function downline_list($ref_id, $parent_id, $dwn_arr, $lev_max, $id_exc, $deep_lev = 5)
    {
        $downline_obj = Customer::where('parent_id', $parent_id)
            ->where('ref_id', '=', $ref_id)
            ->where('status', '=', 'active')
            ->first();
        if (!empty($downline_obj)) {
            $downline_id = $downline_obj->id;
            if ($lev_max <= $deep_lev) {
                $downline_row = Customer::find($downline_id);
                $downline_activation_row = Activation::find($downline_row->activation_type_id);
                if (($downline_id != $id_exc)) {
                    $lev_max++;
                    array_push($dwn_arr, $downline_id);
                }
                return $this->downline_list($ref_id, $downline_id, $dwn_arr, $lev_max, $id_exc, $deep_lev);
            } else {
                return $dwn_arr;
            }} else {
            return $dwn_arr;
        }
    }

    public function set_parent($ref_id)
    {
        $member_row = Customer::find($ref_id);
        $member_last_row = Customer::where('ref_id', $ref_id)
            ->where('status', '=', 'active')
            ->orderBy("activation_at", "desc")
            ->first();
        if (empty($member_last_row) || $member_row->ref_id == 0) {
            $id = $ref_id;
        } else {
            $id = $member_last_row->id;
        }
        return $id;
    }

    public function get_level_num($id, $deep_level, $lev_num = 1)
    {
        $customer = Customer::find($id);
        $parent = Customer::find($customer->parent_id);
        if (!empty($parent) && $lev_num < $deep_level && $parent->status == 'active' && ($customer->parent_id != $customer->ref_id)) {
            $lev_num++;
            return $this->get_level_num($parent->id, $deep_level, $lev_num);
        } else {
            return $lev_num;
        }
    }

    public function get_last_code($type)
    {
        if ($type == "receivable") {
            $account = Payreceivable::where('type', 'receivable')
                ->orderBy('id', 'desc')
                ->first();
            if ($account && (strlen($account->code) == 8)) {
                $code = $account->code;
            } else {
                $code = acc_codedef_generate('REC', 8);
            }
        }
        if ($type == "receivable_trsc") {
            $account = PayreceivableTrs::selectRaw("payreceivables_trs.*")
                ->leftJoin('payreceivables', 'payreceivables_trs.payreceivable_id', '=', 'payreceivables.id')
                ->where('payreceivables_trs.type', '=', 'C')
                ->where('payreceivables.type', '=', 'receivable')
                ->orderBy('payreceivables_trs.id', 'desc')
                ->first();
            if ($account && (strlen($account->code) == 8)) {
                $code = $account->code;
            } else {
                $code = acc_codedef_generate('RTC', 8);
            }
        }
        if ($type == "receivable_trs") {
            $account = PayreceivableTrs::selectRaw("payreceivables_trs.*")
                ->leftJoin('payreceivables', 'payreceivables_trs.payreceivable_id', '=', 'payreceivables.id')
                ->where('payreceivables_trs.type', '=', 'D')
                ->where('payreceivables.type', '=', 'receivable')
                ->orderBy('payreceivables_trs.id', 'desc')
                ->first();
            if ($account && (strlen($account->code) == 8)) {
                $code = $account->code;
            } else {
                $code = acc_codedef_generate('RTD', 8);
            }
        }
        if ($type == "payable") {
            $account = Payreceivable::where('type', 'payable')
                ->orderBy('id', 'desc')
                ->first();
            if ($account && (strlen($account->code) == 8)) {
                $code = $account->code;
            } else {
                $code = acc_codedef_generate('PAY', 8);
            }
        }
        if ($type == "payable_trsc") {
            $account = PayreceivableTrs::selectRaw("payreceivables_trs.*")
                ->leftJoin('payreceivables', 'payreceivables_trs.payreceivable_id', '=', 'payreceivables.id')
                ->where('payreceivables_trs.type', '=', 'C')
                ->where('payreceivables.type', '=', 'payable')
                ->orderBy('payreceivables_trs.id', 'desc')
                ->first();
            if ($account && (strlen($account->code) == 8)) {
                $code = $account->code;
            } else {
                $code = acc_codedef_generate('PTC', 8);
            }
        }
        if ($type == "payable_trs") {
            $account = PayreceivableTrs::selectRaw("payreceivables_trs.*")
                ->leftJoin('payreceivables', 'payreceivables_trs.payreceivable_id', '=', 'payreceivables.id')
                ->where('payreceivables_trs.type', '=', 'D')
                ->where('payreceivables.type', '=', 'payable')
                ->orderBy('payreceivables_trs.id', 'desc')
                ->first();
            if ($account && (strlen($account->code) == 8)) {
                $code = $account->code;
            } else {
                $code = acc_codedef_generate('PTD', 8);
            }
        }
        if ($type == "capitalist") {
            $account = Customer::where('type', 'capitalist')
                ->orderBy('id', 'desc')
                ->first();
            if ($account && (strlen($account->code) == 8)) {
                $code = $account->code;
            } else {
                $code = acc_codedef_generate('KOM', 8);
            }
        }
        if ($type == "customer") {
            $account = Customer::where('type', 'customer')
                ->orderBy('id', 'desc')
                ->first();
            if ($account && (strlen($account->code) == 8)) {
                $code = $account->code;
            } else {
                $code = acc_codedef_generate('CUS', 8);
            }
        }
        if ($type == "capital") {
            $account = Capital::orderBy('id', 'desc')
                ->first();
            if ($account && (strlen($account->code) == 8)) {
                $code = $account->code;
            } else {
                $code = acc_codedef_generate('CAP', 8);
            }
        }

        if ($type == "asset") {
            $account = Asset::orderBy('id', 'desc')
                ->first();
            if ($account && (strlen($account->code) == 8)) {
                $code = $account->code;
            } else {
                $code = acc_codedef_generate('INV', 8);
            }
        }

        if ($type == "sale_retur") {
            $account = Order::where('type', 'sale_retur')
                ->orderBy('id', 'desc')
                ->first();
            if ($account && (strlen($account->code) == 8)) {
                $code = $account->code;
            } else {
                $code = acc_codedef_generate('SRT', 8);
            }
        }

        if ($type == "topup") {
            $account = Order::where('type', 'topup')
                ->orderBy('id', 'desc')
                ->first();
            if ($account && (strlen($account->code) == 8)) {
                $code = $account->code;
            } else {
                $code = acc_codedef_generate('TOP', 8);
            }
        }

        if ($type == "transfer") {
            $account = Order::where('type', 'transfer')
                ->orderBy('id', 'desc')
                ->first();
            if ($account && (strlen($account->code) == 8)) {
                $code = $account->code;
            } else {
                $code = acc_codedef_generate('TRF', 8);
            }
        }

        if ($type == "order") {
            $account = Order::where('type', 'sale')
                ->orderBy('id', 'desc')
                ->first();
            if ($account && (strlen($account->code) == 8)) {
                $code = $account->code;
            } else {
                $code = acc_codedef_generate('ORD', 8);
            }
        }

        if ($type == "order-agent") {
            $account = Order::where('type', 'agent_sale')
                ->orderBy('id', 'desc')
                ->first();
            if ($account && (strlen($account->code) == 8)) {
                $code = $account->code;
            } else {
                $code = acc_codedef_generate('OAG', 8);
            }
        }

        if ($type == "member") {
            $account = Customer::where('type', 'member')
                ->orderBy('id', 'desc')
                ->first();
            if ($account && (strlen($account->code) == 8)) {
                $code = $account->code;
            } else {
                $code = acc_codedef_generate('MBR', 8);
            }
        }

        if ($type == "agent") {
            $account = Customer::where('type', 'agent')
                ->orderBy('id', 'desc')
                ->first();
            if ($account && (strlen($account->code) == 8)) {
                $code = $account->code;
            } else {
                $code = acc_codedef_generate('AGN', 8);
            }
        }

        if ($type == "withdraw") {
            $account = Order::where('type', 'withdraw')
                ->orderBy('id', 'desc')
                ->first();
            if ($account && (strlen($account->code) == 8)) {
                $code = $account->code;
            } else {
                $code = acc_codedef_generate('WDW', 8);
            }
        }

        return $code;
    }

    public function acc_get_last_code($accounts_group_id)
    {
        $account = Account::where('accounts_group_id', $accounts_group_id)
            ->orderBy('code', 'desc')
            ->first();
        if ($account) {
            $code = $account->code;
        } else {
            $accounts_group = AccountsGroup::select('code')->where('id', $accounts_group_id)->first();
            $accounts_group_code = $accounts_group->code;
            $code = acc_codedef_generate($accounts_group_code, 5);
        }

        return $code;
    }

    public function mbr_get_last_code()
    {
        $account = Customer::where('type', 'member')
            ->orderBy('id', 'desc')
            ->first();
        if ($account && (strlen($account->code) == 8)) {
            $code = $account->code;
        } else {
            $code = acc_codedef_generate('MBR', 8);
        }

        return $code;
    }

    public function cst_get_last_code()
    {
        $account = Customer::where('type', '!=', 'member')
            ->orderBy('id', 'desc')
            ->first();
        if ($account && (strlen($account->code) == 8)) {
            $code = $account->code;
        } else {
            $code = acc_codedef_generate('CST', 8);
        }

        return $code;
    }

    public function prd_get_last_code()
    {
        $account = Production::where('type', 'production')
            ->orderBy('id', 'desc')
            ->first();
        if ($account && (strlen($account->code) == 8)) {
            $code = $account->code;
        } else {
            $code = acc_codedef_generate('PRD', 8);
        }

        return $code;
    }

    public function ord_get_last_code()
    {
        $account = Production::where('type', 'sale')
            ->orderBy('id', 'desc')
            ->first();
        if ($account && (strlen($account->code) == 8)) {
            $code = $account->code;
        } else {
            $code = acc_codedef_generate('ORD', 8);
        }

        return $code;
    }

    public function oag_get_last_code()
    {
        $account = Production::where('type', 'agent_sale')
            ->orderBy('id', 'desc')
            ->first();
        if ($account && (strlen($account->code) == 8)) {
            $code = $account->code;
        } else {
            $code = acc_codedef_generate('OAG', 8);
        }

        return $code;
    }

    public function top_get_last_code()
    {
        $account = Production::where('type', 'topup')
            ->orderBy('id', 'desc')
            ->first();
        if ($account && (strlen($account->code) == 8)) {
            $code = $account->code;
        } else {
            $code = acc_codedef_generate('TOP', 8);
        }

        return $code;
    }

    public function get_ref_exc($id, $ref_arr, $lev_max, $id_exc, $deep_lev = 9)
    {
        $customer = Customer::find($id);
        $ref_id = $customer->ref_id;
        if ($ref_id > 0 && $lev_max <= $deep_lev) {
            $referal = Customer::find($ref_id);
            $ref_status = $referal->status;
            if (($ref_id != $id_exc) && ($ref_status == 'active')) {
                array_push($ref_arr, $ref_id);
            }
            $lev_max++;
            return $this->get_ref_exc($ref_id, $ref_arr, $lev_max, $id_exc, $deep_lev);
        } else {
            return $ref_arr;
        }
    }

    public function get_ref($id, $ref_arr, $lev_max)
    {
        $customer = Customer::find($id);
        $ref_id = $customer->ref_id;
        if ($ref_id > 0 && $lev_max <= 9) {
            $referal = Customer::find($ref_id);
            $ref_status = $referal->status;
            if ($ref_status == 'active') {
                array_push($ref_arr, $ref_id);
            }
            $lev_max++;
            return $this->get_ref($ref_id, $ref_arr, $lev_max);
        } else {
            return $ref_arr;
        }
    }
}
