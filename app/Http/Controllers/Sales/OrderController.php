<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrderController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware('account_check');
    }

    public function payment_method_list(Request $request)
    {
        $idToken = $request->header('Authorization');
        $idToken = explode(' ', $idToken);

        # Check Token is 3: 1 is "Token" word, 2 is userID, 3 is the Token 
        if (count($idToken) == 3) {
            DB::beginTransaction();
            try {
                # Select table payment_method where "status" is "ACTIVE"
                $payment_method = DB::table('payment_method')
                    ->where('status', "ACTIVE");

                $respData = $payment_method->select([
                    'id',
                    'name',
                    'detail',
                    'payment_gateway',
                    'image_url',
                ])->get();

                DB::commit();
                $respMessage = trans('messages.ProccessSuccess');
                return $this->respondSuccessWithMessageAndData($respMessage, $respData);
            } catch (\Exception $e) {
                $this->sendApiErrorToTelegram($request->fullUrl(), $request->header(), $request->all(), $e->getMessage());

                DB::rollback();
                $respMessage = trans('messages.ChangeCannotBeDone');
                return $this->respondFailedWithMessage($respMessage);
            }
        }

        $respMessage = trans('messages.Error');
        return $this->respondFailedWithMessage($respMessage);
    }

    public function registration_request_payment(Request $request)
    {
        # Request BODY validation
        $validationRules =  [
            'payment_method_id' => 'required',
        ];
        $errors = $this->staticValidation($request->all(), $validationRules);
        if (count($errors) > 0) {
            $respMessage = $errors->first();
            return $this->respondWithMissingField($respMessage);
        };

        $idToken = $request->header('Authorization');
        $idToken = explode(' ', $idToken);

        # Check Token is 3: 1 is "Token" word, 2 is userID, 3 is the Token 
        if (count($idToken) == 3) {
            DB::beginTransaction();
            try {
                $member_id = $idToken[1];

                // * Checking Member
                # Select table Member
                $member = DB::table('member')
                    ->where('id', $member_id);

                if ($member->get()->count() == 0) {
                    DB::commit();
                    $respMessage = trans('messages.MemberAccountNotFound');
                    return $this->respondFailedWithMessage($respMessage);
                } else if ($member->get()->count() > 1) {
                    DB::commit();
                    $respMessage = trans('messages.MemberRegisteredMoreThanOnce');
                    return $this->respondFailedWithMessage($respMessage);
                }

                # Object "member" with all column selected
                $member = $member->first();

                # Check if "payment_status" already "ACTIVE"
                if ($member->payment_status == 'ACTIVE') {
                    DB::commit();
                    $respMessage = trans('messages.RegistrationPaymentStatusAlreadyVerified');
                    return $this->respondFailedWithMessage($respMessage);
                }

                // * Checking Payment Method
                # Select table "payment_method" where "status" is "ACTIVE"
                $payment_method = DB::table('payment_method')
                    ->where([
                        'id' => (int)$request->input('payment_method_id'),
                    ]);

                if ($payment_method->get()->count() == 0) {
                    DB::commit();
                    $respMessage = trans('messages.PaymentMethodAccountNotFound');
                    return $this->respondFailedWithMessage($respMessage);
                } else if ($payment_method->get()->count() > 1) {
                    DB::commit();
                    $respMessage = trans('messages.PaymentMethodRegisteredMoreThanOnce');
                    return $this->respondFailedWithMessage($respMessage);
                }

                # Object "payment_method" with all column selected
                $payment_method = $payment_method->first();

                if ($payment_method->status != "ACTIVE") {
                    DB::commit();
                    $respMessage = trans('messages.PaymentMethodAccountInactive');
                    return $this->respondFailedWithMessage($respMessage);
                }

                // * Checking Sales item
                # Select table "sales_item" Registration with id = 1
                $sales_item = DB::table('sales_item')
                    ->where([
                        'id' => 1,
                        'name' => 'Registration',
                    ]);

                if ($sales_item->get()->count() == 0) {
                    DB::commit();
                    $respMessage = trans('messages.SalesItemAccountNotFound');
                    return $this->respondFailedWithMessage($respMessage);
                } else if ($sales_item->get()->count() > 1) {
                    DB::commit();
                    $respMessage = trans('messages.SalesItemRegisteredMoreThanOnce');
                    return $this->respondFailedWithMessage($respMessage);
                }

                # Object "sales_item" with all column selected
                $sales_item = $sales_item->first();

                // * Checking Order
                # Check if invoice registration already created
                $order = DB::table('order')
                    ->where([
                        'member_id' => $member_id,
                        'type' => 'REGISTER',
                    ]);

                if ($order->get()->count() > 1) {
                    DB::rollback();
                    $respMessage = trans('messages.OrderRegisteredMoreThanOnce');
                    return $this->respondFailedWithMessage($respMessage);
                } else if ($order->get()->count() == 1) {
                    // * Invoice already generated
                    // TODO invoice already generated
                } else {
                    // * Invoice not generated

                    # Generate invoice
                    $YmdHis = date('YmdHis');
                    $random_5_digit_string = strtoupper(Str::random(5));
                    $invoice = "REG$YmdHis$random_5_digit_string";

                    # Create Cart
                    $cart = [
                        [
                            'order_id' => null,
                            'sales_item_id' => $sales_item->id,
                            'quantity' => 1,
                        ],
                    ];

                    $grand_total_price = $sales_item->price;

                    # Insert to table "order"
                    $valueDB = [
                        'member_id' => $member_id,
                        'payment_method_id' => $payment_method->id,
                        'type' => 'REGISTER',
                        'invoice' => $invoice,
                        'status' => 'REQPAYMENT',
                        'date_inquiry' => date('Y-m-d H:i:s'),
                        'total_price' => $sales_item->price,
                        'grand_total_price' => $grand_total_price,
                        'created_at' => date('Y-m-d H:i:s'),
                    ];
                    # Insert to Database and get ID in return
                    $order_id = DB::table('order')->insertGetId($valueDB);

                    # Insert Cart to table "order_item"
                    foreach ($cart as $key => $value) {
                        $cart[$key]['order_id'] = $order_id;

                        # Insert to table "order_item"
                        DB::table('order_item')->insert($cart[$key]);
                    }

                    # Xendit Create Invoice
                    $cURL_url = 'https://api.xendit.co/v2/invoices';
                    $cURL_headers = [
                        'Content-Type: application/json',
                    ];
                    $pg_description = "PAYMENT BRN REGISTRATION. INVOICE: $invoice, IDR " . number_format($grand_total_price);
                    $cURL_body = [
                        'external_id' => $invoice,
                        'amount' => $grand_total_price,
                        'payer_email' => $member->email_address,
                        'description' => $pg_description,
                        // 'callback_virtual_account_id' => , // Fixed Virtual Account
                    ];
                    $XENDIT_SECRET_KEY = env('XENDIT_SECRET_KEY');
                    $cURL_basic_auth = "$XENDIT_SECRET_KEY:";

                    $curlRequest = $this->curlRequest($cURL_url, 'POST', $cURL_headers, $cURL_body, $cURL_basic_auth);
                    $curlRequest = json_decode($curlRequest);

                    # Check if cURL request is success by checking is "id" available
                    if (isset($curlRequest->id)) {
                        $payment_code = $curlRequest->invoice_url;

                        # Insert to table "order_payment"
                        $valueDB = [
                            'order_id' => $order_id,
                            'payment_method_id' => $payment_method->id,
                            'status' => 'INQUIRY',
                            'request' => json_encode($cURL_body),
                            'response' => json_encode($curlRequest),
                            'payment_code' => $payment_code,
                            'created_at' => date('Y-m-d H:i:s'),
                        ];
                        # Insert to Database and get ID in return
                        $order_id = DB::table('order_payment')->insertGetId($valueDB);

                        DB::commit();
                        $respData = [
                            'invoice' => $invoice,
                            'grand_total_price' => $grand_total_price,
                            'payment_method' => "$payment_method->name $payment_method->detail $payment_method->payment_gateway",
                            'payment_code' => $payment_code,
                        ];
                        $respMessage = trans('messages.ProccessSuccess');
                        return $this->respondSuccessWithMessageAndData($respMessage, $respData);
                    } else {
                        $teleError = [
                            'Title' => 'RequestPaymentFailed',
                            'Request' => $cURL_body,
                            'Response' => $curlRequest,
                        ];
                        $this->sendApiErrorToTelegram($request->fullUrl(), $request->header(), $request->all(), $teleError);

                        DB::rollback();
                        $respMessage = trans('messages.RequestPaymentFailed');
                        return $this->respondFailedWithMessage($respMessage);
                    }
                }
            } catch (\Exception $e) {
                $this->sendApiErrorToTelegram($request->fullUrl(), $request->header(), $request->all(), $e->getMessage());

                DB::rollback();
                $respMessage = trans('messages.ChangeCannotBeDone');
                return $this->respondFailedWithMessage($respMessage);
            }
        }

        $respMessage = trans('messages.Error');
        return $this->respondFailedWithMessage($respMessage);
    }
}