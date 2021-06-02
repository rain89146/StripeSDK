<?php
    $MAIN_PATH = (PHP_OS === 'Linux' || PHP_OS === 'Darwin') ? str_replace('/classes', '', __DIR__) . '/vendor/autoload.php' : str_replace('\classes', '', __DIR__) . '\vendor\autoload.php';
    require $MAIN_PATH;

    //   
        class Stripe {

            private $CLIENT_ID;
            private $API_KEY;
            private $REDIRECT;

            private $LOGIN_TOKEN;

            private $TOOLS;
            private $DB;

            private $USER_ID;
            private $USER_ST_API_KEY;
            private $USER_ST_ACC_ID;

            /**
             * Constructor
             * @param string $sk        Stripe token
             */
            public function __construct() {
                
                //  get the company_id from php.ini file
                    $PATH = (PHP_OS === 'Linux' || PHP_OS === 'Darwin') ? str_replace('/classes', '',__DIR__) .'/php.ini' : str_replace('\classes', '',__DIR__) . '\php.ini';
                    $CONIFG = parse_ini_file($PATH);

                //  stripe key and client
                    $this->API_KEY = $CONIFG['STRIPE_API'];
                    $this->CLIENT_ID = $CONIFG['STRIPE_CLIENT_ID'];
                    $this->REDIRECT = $CONIFG['STRIPE_REDIRECT'];

                //  get the stripe api                    
                    $this->TOOLS = new Tools();
                    $this->DB = new DB();
            }

            //OAUTH 2.0 =========================================//

            /**
             * Set login token
             */
            public function set_login_token(
                string $login_token
            ) {
                $this->LOGIN_TOKEN = $login_token;

                //  look for the user id
                $user_id = $this->DB->get_row('tap_login', ['token'=>$login_token], ['user_id']);

                if(FALSE !== $user_id){

                    $user_id = $user_id['data']['user_id'];
                    $this->USER_ID = $user_id;

                    $user_api = $this->get_st_data(['user_id'=>$user_id], ['access_token', 'stripe_user_id', 'stripe_publishable_key', 'livemode']);
                    if(FALSE !== $user_api){
                        
                        $live_mode = $user_api['data']['livemode'];
                        $live_mode = ($live_mode == '0') ? FALSE : TRUE;

                        $this->USER_ST_ACC_ID = $user_api['data']['stripe_user_id'];
                        $this->USER_ST_API_KEY = ($live_mode === FALSE) ? $user_api['data']['access_token'] : $user_api['data']['stripe_publishable_key'];
                    }
                }
            }

            /**
             * Generate auth url
             * @return string $url
             */
            public function generate_auth_url()
            {
                $client_id = $this->CLIENT_ID;
                $redirect = $this->REDIRECT;
                $login_token = $this->LOGIN_TOKEN;
                $url = "https://connect.stripe.com/oauth/authorize?response_type=code&client_id=$client_id&scope=read_write&redirect_uri=" . $redirect . "&state=" . $login_token;
                return ['status'=>TRUE, 'url'=>$url];
            }

            /**
             * Authorize token
             * @param string $code
             */
            public function authorize_token(
                string $code
            ) {
                try {

                    \Stripe\Stripe::setApiKey($this->API_KEY);

                    $response = \Stripe\OAuth::token([
                        'grant_type' => 'authorization_code',
                        'code' => $code,
                    ]);
                    
                    $response = $this->TOOLS->std_to_array($response);
                    return ['status'=>TRUE, 'data'=>$response];

                } catch (Exception $e) {
                    return ['status'=>FALSE, 'msg'=>$e->getMessage()];
                }
            }

            /**
             * Init stripe
             */
            protected function init()
            {
                $user_api = $this->USER_ST_API_KEY;
                $default_api = $this->API_KEY;

                $api_key = (isset($user_api)) ? $user_api : $default_api;

                $stripe = new \Stripe\StripeClient($default_api);
                return $stripe;
            }

            //CUSTOMER =========================================//

            /**
             * Create user
             * @param array $array      Customer object
             */
            public function CreateUser(
                array $param
            ) {
                try {
                    
                    // $email = $param['email'];
                    // $phone = $param['phone'];

                    // $found_email = $this->DB->get_row('tap_stripe_customer', ['email'=>$email], ['row_id']);
                    // $found_email = (FALSE !== $found_email['status']) ? TRUE : FALSE;
                    
                    // $found_phone = $this->DB->get_row('tap_stripe_customer', ['phone'=>$phone], ['row_id']);
                    // $found_phone = (FALSE !== $found_phone['status']) ? TRUE : FALSE;

                    // if($found_email === FALSE && $found_phone === FALSE){

                        //  Connect to stripe
                        $stripe = $this->init();

                        //  Customer param
                        $customer_param = [
                            'email' => (isset($param['email'])) ? $param['email'] : null,
                            'phone' => (isset($param['phone'])) ? $param['phone'] : null,
                            'address' => [
                                'city'=> (isset($param['city'])) ? $param['city'] : null,
                                'country' => (isset($param['country'])) ? $param['country'] : null,
                                'line1' => (isset($param['address_line1'])) ? $param['address_line1'] : null,
                                'line2' => (isset($param['address_line2'])) ? $param['address_line2'] : null,
                                'postal_code' => (isset($param['postal_code'])) ? $param['postal_code'] : null,
                                'state' => (isset($param['state'])) ? $param['state'] : null,
                            ],
                            'name' => (isset($param['name'])) ? $param['name'] : null,
                        ];

                        //  Create customer
                        $customer = $stripe->customers->create($customer_param);

                        //  add customer to the record
                        $created = $this->DB->add_row('tap_stripe_customer', [
                            'email' => $param['email'],
                            'phone' => $param['phone'],
                            'name' => $param['name'],
                            'customer_id' => $customer->id,
                            'user_id' => $this->USER_ID
                        ]);

                        //  return customer id
                        return ['status'=>TRUE, 'customer_id'=>$customer->id];

                    // }else{
                    //     throw new Exception('Customer already exists');
                    // }

                } catch (Exception $e) {

                    return ['status'=>FALSE, 'msg'=>$e->getMessage()];
                }
            }

            /**
             * Retrieve customer information
             * @param string $customer_id          Customer id
             */
            public function RetrieveCustomer(
                string $customer_id
            ) {
                try {
                    $stripe = $this->init();
                    $customer = $stripe->customers->retrieve($customer_id, []);
                    $customer = $this->TOOLS->std_to_array($customer);
                    return ['status'=>TRUE, 'data'=>$customer];    
                } catch (Exception $e) {
                    return ['status'=>FALSE, 'msg'=>$e->getMessage()];
                }
            }

            /**
             * Update customer existing record
             * @param string $customer_id           Customer id
             * @param array $customer_param         Customer information
             */
            public function UpdateCustomer(
                string $customer_id,
                array $param
            ) {
                try {
                    
                    //  Init the stripe
                    $stripe = $this->init();

                    //  Customer param, dynamic update
                    $customer_param = [];

                    //
                    foreach ($param as $k => $v) {
                        $customer_param[$k] = $v;
                    }

                    $customer = $stripe->customers->update($customer_id, $customer_param);
                    return ['status'=>TRUE, 'customer'=>$customer];

                } catch (Exception $e) {
                    return ['status'=>FALSE, 'msg'=>$e->getMessage()];
                }
                
            }

            /**
             * Delete the customer from stripe
             * @param string $customer_id
             */
            public function DeleteCustomer(
                string $customer_id
            ) {
                try {
                    $stripe = $this->init();
                    $delete = $stripe->customers->delete($customer_id, []);
                    return ['status'=>TRUE, 'deleted'=>$delete->deleted];
                } catch (Exception $e) {
                    return ['status'=>FALSE, 'msg'=>$e->getMessage()];
                }
            }

            /**
             * Get all the customer
             */
            public function GetAllCustomer()
            {
                try {
                    $stripe = $this->init();
                    $all = $stripe->customers->all();
                    return ['status'=>TRUE, 'customers'=>$all->data];
                } catch (Exception $e) {
                    return ['status'=>FALSE, 'msg'=>$e->getMessage()];
                }
            }

            //PAYMENT METHOD=========================================//

            /**
             * @param string $payment_type      e.g. card
             * @param array $payment_param
             * @return mixed status and payment id
             */
            public function CreatePaymentMethod(
                string $payment_type, // card
                array $payment_param,
                $billing_param = null
            ) {
                $create_param = [
                    'type' => $payment_type,
                    $payment_type => $payment_param,
                ];

                if(!is_null($billing_param)){
                    $create_param['billing_details'] = $billing_param;
                }

                try {
                    $stripe = $this->init();
                    $payment_method = $stripe->paymentMethods->create($create_param);
                    return [
                        'status'=>TRUE, 
                        'payment_id'=>$payment_method->id, 
                    ];
                } catch (Exception $e) {
                    return ['status'=>FALSE, 'msg'=>$e->getMessage()];
                }
            }

            /**
             * Payment attach to customer
             * @param string $customer_id           customer id
             * @param string $payment_id            Payment id
             */
            public function PaymentAttachCustomer(
                string $customer_id, 
                string $payment_id
            ) {
                try {
                    $stripe = $this->init();
                    $attached = $stripe->paymentMethods->attach($payment_id, ['customer' => $customer_id]);
                    return [
                        'status'=>TRUE, 
                        'payment_id'=>$attached->id, 
                        'card_type'=>$attached->card->brand,
                        'last4'=>$attached->card->last4,
                        'exp_month'=>$attached->card->exp_month,
                        'exp_year'=>$attached->card->exp_year
                    ];
                } catch (Exception $e) {
                    return ['status'=>FALSE, 'msg'=>$e->getMessage()];
                }
            }

            /**
             * Payment detach from customer
             * @param string $payment_id            Customer id
             */
            public function PaymentDetachCustomer(
                string $payment_id
            ) {
                try {
                    $stripe = $this->init();
                    $detached = $stripe->paymentMethods->detach($payment_id, []);
                    return ['status'=>TRUE, 'payment_id'=>$detached->id];
                } catch (Exception $e) {
                    return ['status'=>FALSE, 'msg'=>$e->getMessage()];
                }
            }

            /**
             * Get all the payments from customer
             * @param string $customer_id
             */
            public function GetAllPaymentsFromCustomer(
                string $customer_id,
                string $payment_type
            ) {
                try {
                    $stripe = $this->init();
                    $methods = $stripe->paymentMethods->all([
                        'customer' => $customer_id,
                        'type' => $payment_type,
                    ]);
                    $methods = $this->TOOLS->std_to_array($methods);
                    return ['status'=>TRUE, 'methods'=>$methods['data']];
                } catch (Exception $e) {
                    return ['status'=>FALSE, 'msg'=>$e->getMessage()];
                }
            }

            //CHARGE =========================================//
            
            public function CreateCharge(
                array $param
            ) {
                try {
                    $stripe = $this->init();
                    $created = $stripe->charges->create([
                        'amount' => $param['amount'],
                        'currency' => $param['currency'],
                        'source' => $param['source'],
                        'description' => $param['description'],
                    ]);
                    return ['status'=>TRUE, 'charge_id'=>$created->id];
                }catch(Exception $e){
                    return ['status'=>FALSE, 'msg'=>$e->getMessage()];
                }
            }

            public function RetrieveCharge()
            {
                # code...
            }

            //PAYMENT INTENT =========================================//
            
            /**
             * Create payment charge
             * @param array $param          includes [amount, currency, description, customer_id]
             */
            public function CreatePaymentCharge(
                array $param
            ) {

                $charge_param = [
                    'amount' => $param['amount'],
                    'currency' => $param['currency'],
                    'description' => $param['description']
                ];

                if(isset($param['customer'])){
                    $charge_param['customer'] = $param['customer'];
                    $charge_param['payment_method_types'] = ['card'];
                }

                try {

                    $stripe = $this->init();
                    $created = $stripe->paymentIntents->create($charge_param);
                    return ['status'=>TRUE, 'charge_id'=>$created->id];
                
                }catch(Exception $e){
                    return ['status'=>FALSE, 'msg'=>$e->getMessage()];
                }
            }
            
            /**
             * Confirm the payment charge
             * @param string $charge_id         the charge_id that CreatePaymentCharge returned
             * @param string $method_id         the methoid that CreatePaymentMethod returned
             */ 
            public function ConfirmPaymentCharge(
                string $charge_id,
                string $method_id
            ) {
                try {
                    $stripe = $this->init();
                    $confirmed = $stripe->paymentIntents->confirm(
                        $charge_id,
                        ['payment_method' => $method_id]
                    );
                    
                    $status = ('succeeded' == $confirmed->status) ? TRUE : FALSE;
                    
                    return ['status'=>$status];

                }catch(Exception $e){
                    return ['status'=>FALSE, 'msg'=>$e->getMessage()];
                }
            }

            /**
             * Retrieve payment charge
             * @param string $payment_id        
             */
            public function RetrievePaymentCharge(
                string $payment_id
            ) {
                try {
                    $stripe = $this->init();
                    $retrieved = $stripe->paymentIntents->retrieve($payment_id, []);
                    return ['status'=>TRUE, 'payment_intent'=>$retrieved];
                } catch (Exception $e) {
                    return ['status'=>FALSE, 'msg'=>$e->getMessage()];
                }
            }
            
            /**
             * Get all payment charge
             */
            public function GetAllPaymentCharge()
            {
                try {
                    $stripe = $this->init();
                    $all = $stripe->paymentIntents->all();
                    return ['status'=>TRUE, 'payments'=>$all['data']];
                } catch (Exception $e) {
                    return ['status'=>FALSE, 'msg'=>$e->getMessage()];
                }
            }

            /**
             * Cancel payment charge
             * @param string $payment_id        The payment id that CreatePaymentCharge returned
             */
            public function CancelPaymentCharge(
                string $payment_id
            ) {
                try {
                    $stripe = $this->init();
                    $canceled = $stripe->paymentIntents->cancel($payment_id, []);
                    $res = ($canceled->status === 'canceled') ? TRUE : FALSE;
                    return ['status'=>TRUE, 'canceled'=>$res];
                } catch (Exception $e) {
                    return ['status'=>FALSE, 'msg'=>$e->getMessage()];
                }
            }

            //REFUND =========================================//
            
            /**
             * Create refund
             * @param string $payment_id        The payment id that CreatePaymentCharge returned
             */
            public function CreateRefund(   
                string $payment_id,
                array $param
            ) {
                try {
                    $stripe = $this->init();
                    $refund = $stripe->refunds->create([
                        'amount' => $param['amount'],
                        'payment_intent' => $payment_id,
                        'reason' => $param['reason']
                    ]);
                    return ['status'=>TRUE, 'refund_id'=>$refund->id, 'refund_status'=>$refund->status];
                } catch (Exception $e) {
                    return ['status'=>FALSE, 'msg'=>$e->getMessage()];
                }    
            }

            //BANK ACCOUNT =========================================//

            /**
             * Create bank account
             * 1. Get the customer id and 
             * 2. Assign bank account
             */
            public function CreateBankAccount(
                string $customer_id,
                array $param
            ) {
                try {
                    //code...
                    $stripe = $this->init();
                    $created = $stripe->customers->createSource(
                        $customer_id,
                        [
                            'source' => $param['source'],
                            'object' => 'bank_account',
                            'currency' => 'usd',
                            'account_holder_name' => $param['holder_name'],
                            'account_holder_type' => $param['holder_type'],
                            'routing_number' => $param['routing_number'],
                            'account_number' => $param['account_number']
                        ]
                    );
                    return ['status'=>TRUE, 'bank_id'=>$created->id];
                } catch (Exception $e) {
                    return ['status'=>FALSE, 'msg'=>$e->getMessage()];
                }
            }

            /**
             * Get bank account information
             * @param string $customer_id
             * @param string $bank_id
             */
            public function RetrieveBank(
                string $customer_id,
                string $bank_id
            ) {
                try {
                    $stripe = $this->init();
                    $bank = $stripe->customers->retrieveSource($customer_id, $bank_id, []);
                    return ['status'=>TRUE, 'bank'=>$bank];
                } catch (Exception $e) {
                    return ['status'=>FALSE, 'msg'=>$e->getMessage()];
                }
            }

            /**
             * Verify bank
             * @param string $customer_id
             * @param string $bank_id
             */
            public function VerifyBank(
                string $customer_id,
                string $bank_id
            ) {
                try {
                    $stripe = $this->init();
                    $bank = $stripe->customers->verifySource($customer_id, $bank_id, ['amounts' => [32, 45]]);
                    return ['status'=>TRUE, 'bank'=>$bank, 'verified_status'=>$bank->status];
                } catch (Exception $e) {
                    return ['status'=>FALSE, 'msg'=>$e->getMessage()];
                }
            }

            /**
             * Delete bank
             * @param string $customer_id
             * @param string $bank_id
             */
            public function DeleteBank(
                string $customer_id,
                string $bank_id  
            ) {
                try {
                    $stripe = $this->init();
                    $bank = $stripe->customers->deleteSource($customer_id, $bank_id, []);
                    return ['status'=>TRUE, 'deleted'=>$bank->deleted];
                } catch (Exception $e) {
                    return ['status'=>FALSE, 'msg'=>$e->getMessage()];
                }
            }
            
            //ACCOUNT =========================================//

            public function create_account(
                array $param
            ) {
                try {
                    
                    $stripe = $this->init();
                    $created = $stripe->accounts->create($param);
                    return ['status'=>TRUE, 'result'=>$created];

                } catch (Exception $e) {
                    return ['status'=>FALSE, 'msg'=>$e->getMessage()];
                }
            }

            public function retrieve_account(
                string $account_id
            ) {
                try {
                    
                    $stripe = $this->init();
                    $retrieved = $stripe->accounts->retrieve($account_id, []);
                    return ['status'=>TRUE, 'result'=>$retrieved];

                } catch (Exception $e) {
                    return ['status'=>FALSE, 'msg'=>$e->getMessage()];
                }
            }

            //CRUD =========================================//
            
            public function get_st_data(
                array $condition,
                $look_for = "*"
            ) {
                $get = $this->DB->get_row('tap_stripe', $condition, $look_for);
                return $get;
            }

            public function add_st_data(
                array $param  
            ) {
                $add = $this->DB->add_row('tap_stripe', $param);
                return $add;
            }

            public function set_st_data(
                array $param,
                array $condition
            ) {
                $set = $this->DB->set_row('tap_stripe', $param, $condition);
                return $set;
            }

        }
?>