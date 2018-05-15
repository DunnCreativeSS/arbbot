<?php

require_once __DIR__ . '/Exchange.php';

// This class implements the common functionality among exchanges that share a
// similar API to HitBTC.
abstract class HitBTCLikeExchange extends Exchange {

  private $marketOptions = [ ];
  private $orderIDField = '';
  private $orderIDParam = '';
  private $orderbookBothPhrase = '';
  private $minTradeSize = [ ];

  protected abstract function getPublicURL();
  protected abstract function getPrivateURL();

  function __construct( $apiKey, $apiSecret, $marketOptions) {
    parent::__construct( $apiKey, $apiSecret );
    $this->marketOptions = $marketOptions;
  }

  public function getTickers( $currency ) {

    $ticker = [ ];

    $markets = $this->queryMarketSummary();

    foreach ( $markets as $market ) {

      $market = json_encode($market, true);
      $market = json_decode($market, true);
      $name = $this->parseMarketName( $market[ 'symbol' ] );
      if ( $name[ 'currency' ] != $currency ) {
        continue;
      }

      $ticker[ $name[ 'tradeable' ] ] = $market[ 'last' ];
    }

    return $ticker;

  }

  public function withdraw( $coin, $amount, $address, $tag = null ) {

    try {
      $this->queryWithdraw( $coin, $amount, $address, $tag );
      return true;
    }
    catch ( Exception $ex ) {
      echo( $this->prefix() . "Withdrawal error: " . $ex->getMessage() );
      return false;
    }

  }

  public function getDepositAddress( $coin ) {
sleep(1.5);
    return $this->queryDepositAddress( $coin );

  }

  public function buy( $tradeable, $currency, $rate, $amount ) {

    try {
      return $this->queryOrder( $tradeable, $currency, 'buy', $rate, $amount );
    }
    catch ( Exception $ex ) {
      if ( strpos( $ex->getMessage(), '_OFFLINE' ) !== false ) {
        $this->onMarketOffline( $tradeable );
      }
      logg( $this->prefix() . "Got an exception in buy(): " . $ex->getMessage() );
      return null;
    }

  }

  public function sell( $tradeable, $currency, $rate, $amount ) {

    try {
      return $this->queryOrder( $tradeable, $currency, 'sell', $rate, $amount );
    }
    catch ( Exception $ex ) {
      if ( strpos( $ex->getMessage(), '_OFFLINE' ) !== false ) {
        $this->onMarketOffline( $tradeable );
      }
      logg( $this->prefix() . "Got an exception in sell(): " . $ex->getMessage() );
      return null;
    }

  }

  protected function fetchOrderbook( $tradeable, $currency ) {

    $orderbook = $this->queryOrderbook( $tradeable, $currency );

      $orderbook = json_encode($orderbook, true);
      $orderbook = json_decode($orderbook, true);
    if ( count( $orderbook ) == 0 ) {
      return null;
    }

    $ask = $orderbook[ 'ask' ];
    if ( count( $ask ) == 0 ) {
      return null;
    }

    $bestAsk = $ask[ 0 ];

    $bid = $orderbook[ 'bid' ];
    if ( count( $bid ) == 0 ) {
      return null;
    }

    $bestBid = $bid[ 0 ];


    return new Orderbook( //
            $this, $tradeable, //
            $currency, //
            new OrderbookEntry( $bestAsk[ 'size' ], $bestAsk[ 'price' ] ), //
            new OrderbookEntry( $bestBid[ 'size' ], $bestBid[ 'price' ] ) //
    );

  }

  public function cancelAllOrders() {

    $orders = $this->queryOpenOrders();
    foreach ( $orders as $order ) {
      $id = $order[ $this->orderIDField ];
      $this->cancelOrder( $id );
    }

  }

  public function refreshExchangeData() {

    $pairs = [ ];
    $markets = ($this->queryMarkets());
    $currencies = ($this->queryCurrencies());

    // This is a list of tradeables that have a market. Used to filter the
    // tx-fee list, which is later used to seed the wallets
    $tradeables = [ ];
    $this->minTradeSize = [ ];
    foreach ( $markets as $market ) {
      $market = json_encode($market, true);
      $market = json_decode($market, true);
      $tradeable = $market[ 'baseCurrency' ];
      $currency = $market[ 'quoteCurrency' ];

      if ( !Config::isCurrency( $currency ) ||
           Config::isBlocked( $tradeable ) ) {

        continue;
      }

      $tradeables[] = $tradeable;
      $pair = $tradeable . '_' . $currency;
      $pairs[] = $pair;
      $this->minTradeSize[ $pair ] = $market[ 'tickSize' ];
    }

    $names = [ ];
    $txFees = [ ];
    $conf = [ ];

    foreach ( $currencies as $data ) {

      $data = json_encode($data, true);
      $data = json_decode($data, true);
      $coin = strtoupper( $data[ 'id' ] );
      $type = strtoupper( $data[ 'crypto' ] );
      if ( $coin == 'BTC' ||
           array_search( $coin, $tradeables ) !== false ) {
        $names[ $coin ] = strtoupper( $data[ 'fullName' ] );
        $txFees[ $coin ] = (float) $data[ 'payoutFee' ] . ($type == 'BITCOIN_PERCENTAGE_FEE' ? '%' : '');
        //$conf[ $coin ] = $data[ 'MinConfirmation' ];
      }
    }

    $this->pairs = $pairs;
    $this->names = $names;
    $this->withdrawFees = $txFees;
    $this->confirmationTimes = 100;

    $this->calculateTradeablePairs();

  }

  public function getPrecision( $tradeable, $currency ) {

    // Hardcode the precision
    return array( 'amount' => 8, 'price' => 8 );

  }

  public function getLimits( $tradeable, $currency ) {

    // Hardcode most of the limits
    $pair = $tradeable . '_' . $currency;
    return array(
      'amount' => array (
          'min' => $this->minTradeSize[ $pair ],
          'max' => 1000000000,
      ),
      'price' => array (
          'min' => 0.00000001,
          'max' => 1000000000,
      )
    );

  }

  private function onMarketOffline( $tradeable ) {
    $keys = array( );
    foreach ( $this->pairs as $pair ) {
      if ( startsWith( $pair, $tradeable . '_' ) ) {
        $keys[] = $pair;
      }
    }
    foreach ( $keys as $key ) {
      unset( $this->pairs[ $key ] );
    }

    unset( $this->names[ $tradeable ] );
    unset( $this->withdrawFees[ $tradeable ] );
    unset( $this->confirmationTimes[ $tradeable ] );
  }

  public function detectStuckTransfers() {

    // TODO: Detect stuck transfers!

  }

  public function detectDuplicateWithdrawals() {

    // TODO: Detect duplicate withdrawals!

  }

  public function dumpWallets() {

    logg( $this->prefix() . print_r( $this->queryBalances(), true ) );

  }

  public function refreshWallets( $tradesMade = array() ) {

    $this->preRefreshWallets();

    $wallets = [ ];

    // Create artifical wallet balances for all traded coins:
    $currencies = $this->getTradeables();
    if (!count( $currencies )) {
      // If this is the first call to refreshWallets(), $this->withdrawFees isn't
      // initialized yet.
      $currencies = $this->queryCurrencies();
    }
    foreach ( $currencies as $currency ) {
      $currency = json_encode($currency, true);
      $currency = json_decode($currency, true);
      if ( $currency[ 'CoinType' ] != 'Bitcoin' ) {
        // Ignore non-BTC assets for now.
        continue;
      }
      $wallets[ $currency[ 'Currency' ] ] = 0;
    }
    $wallets[ 'BTC' ] = 0;

    $balances = $this->queryBalances();

    foreach ( $balances as $balance ) {
      $balance = json_encode($balance, true);
      $balance = json_decode($balance, true);
      $wallets[ strtoupper( $balance[ 'currency' ] ) ] = floatval( $balance[ 'available' ] );
    }

    $this->wallets = $wallets;

    $this->postRefreshWallets( $tradesMade );

  }

  public function testAccess() {

    $this->queryBalances();

  }

  // Internal functions for querying the exchange

  private function queryDepositAddress( $coin ) {

    for ( $i = 0; $i < 3; $i++ ) {
      try {
        sleep(1);
        $data = $this->queryPostAPI( 'account/crypto/address/'. $coin );
        return isset( $data[ 'address' ] ) ? $data[ 'address' ] : null;
      }
      catch ( Exception $ex ) {
        if ( strpos( $ex->getMessage(), 'ADDRESS_GENERATING' ) !== false ) {
         logg($ex->getMessage());
          sleep( 30 );
          continue;
        }
        if ( strpos( $ex->getMessage(), '_OFFLINE' ) !== false ) {
           logg($ex->getMessage());
          $this->onMarketOffline( $coin );
        }
         logg($ex->getMessage());
        throw $ex;
      }
    }

    return null;

  }

  private function queryOrderbook( $tradeable, $currency ) {
    logg($this->makeMarketName( $currency, $tradeable ));
    return json_decode($this->xtractResponse( $this->queryPublicJSON( $this->getPublicURL() . 'orderbook/' . $this->makeMarketName( $currency, $tradeable ) ) ));

  }

  private function queryMarkets() {
    return json_decode($this->xtractResponse( $this->queryPublicJSON( $this->getPublicURL() . 'symbol' ) ));

  }

  private function queryCurrencies() {
    return json_decode($this->xtractResponse( $this->queryPublicJSON( $this->getPublicURL() . 'currency' ) ));

  }

  private function queryMarketSummary() {
    return json_decode($this->xtractResponse( $this->queryPublicJSON( $this->getPublicURL() . 'ticker' ) ));

  }

  protected function queryCancelOrder( $id ) {
    return json_decode($this->queryAPI( 'order/'.$this->orderIDParam));
    

  }

  private function queryOpenOrders() {
    return json_decode($this->queryAPI( 'order' ));

  }

  protected function makeMarketName( $currency, $tradeable ) {

    $arr = [ $currency, $tradeable ];
    return $arr[ $this->marketOptions[ 'offsetCurrency' ] ] . $this->marketOptions[ 'separator' ] . $arr[ $this->marketOptions[ 'offsetTradeable' ] ];

  }

  protected function parseMarketName( $name ) {

    $split = explode( $this->marketOptions[ 'separator' ], $name );
    return array(
      'tradeable' => $split[ $this->marketOptions[ 'offsetTradeable' ] ],
      'currency' => $split[ $this->marketOptions[ 'offsetCurrency' ] ],
    );

  }

  private function queryOrder( $tradeable, $currency, $orderType, $rate, $amount ) {

    $result = $this->queryAPI( 'order', //
            [
            'type' => strtolower( $orderType ),
        'symbol' => $this->makeMarketName( $currency, $tradeable ),
        'quantity' => formatBTC( $amount ),
        'price' => formatBTC( $rate )
            ]
    );

    if ( !isset( $result[ $this->orderIDParam ] ) ) {
      return null;
    }

    return $result[ $this->orderIDParam ];

  }

  private function queryBalances() {
    return json_decode($this->queryAPI( 'account/balance' ));

  }

  public function withdrawSupportsTag() {

    return false;

  }

  protected function queryWithdraw( $coin, $amount, $address, $tag ) {
    return $this->queryAPI( 'account/crypto/withdraw', //
                    [
                'currency' => $coin,
                'amount' => formatBTC( $amount ),
                'address' => $address
                    ]
    );

  }

  protected function xtractResponse( $response ) {

    $data = $response;

    if ( !$data ) {
      throw new Exception( "Invalid data received: (" . $response . ")" );
    }

    return $data;

  }
 private function _signature($uri, $postData)
    {
        return strtolower(hash_hmac('sha512', $uri . $postData, $this->apiSecret));
    }
    protected function queryPostAPI( $method, $req = [] ){

    $key = $this->apiKey;
    $secret = $this->apiSecret;
    $nonce = $this->nonce();

    $req[ 'apikey' ] = $key;
    $req[ 'nonce' ] = sprintf( "%ld", $nonce );

    $uri = $this->getPrivateURL() . $method;
    $sign = hash_hmac( 'sha512', $uri, $secret );

    static $ch = null;
    if ( is_null( $ch ) ) {
      $ch = curl_init();
      curl_setopt( $ch, CURLOPT_RETURNTRANSFER, TRUE );
      curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, TRUE );
      curl_setopt( $ch, CURLOPT_USERAGENT, 'Mozilla/4.0 (compatible; Cryptsy API PHP client; ' . php_uname( 's' ) . '; PHP/' . phpversion() . ')' );
    }
    curl_setopt( $ch, CURLOPT_URL, $uri );
    curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 10 );
    curl_setopt( $ch, CURLOPT_TIMEOUT, 180 );
curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_USERPWD, $key . ":" . $secret);  


    $error = null;
    for ( $i = 0; $i < 5; $i++ ) {
      try {
        $data = curl_exec( $ch );
        $code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
        if ($code != 200) {
          if ((json_decode($data, 1))['error']['code'] == 600){
logg('cannot deposit..');
return 600;
}
        if ($code == 429){
          sleep(11);
        }
          throw new Exception( "HTTP ${code} received from server" );
        }
        //
        if ( $data === false ) {
          $error = $this->prefix() . "Could not get reply: " . curl_error( $ch );
          logg( $error );
          continue;
        }

        return $this->xtractResponse( $data );
      }
      catch ( Exception $ex ) {
        $error = $ex->getMessage();
        logg( $this->prefix() . $error );

        if ( strpos( $error, 'ORDER_NOT_OPEN' ) !== false ||
             strpos( $error, 'MIN_TRADE_REQUIREMENT_NOT_MET' ) !== false ||
             strpos( $error, 'ADDRESS_GENERATING' ) !== false ||
             strpos( $error, '_OFFLINE' ) !== false ) {
          // Real error, don't attempt to retry needlessly.
          break;
        }

        // Refresh request parameters
        $nonce = $this->nonce();
        $req[ 'nonce' ] = sprintf( "%ld", $nonce );
        $uri = $this->getPrivateURL() . $method . '?' . http_build_query( $req );
        $sign = hash_hmac( 'sha512', $uri, $secret );
        curl_setopt( $ch, CURLOPT_URL, $uri );
        curl_setopt($ch, CURLOPT_USERPWD, $key . ":" . $secret);  
        continue;
      }
    }
    throw new Exception( $error );

    } 
  protected function queryAPI( $method, $req = [ ] ) {

    $key = $this->apiKey;
    $secret = $this->apiSecret;
    $nonce = $this->nonce();

    $req[ 'apikey' ] = $key;
    $req[ 'nonce' ] = sprintf( "%ld", $nonce );

    $uri = $this->getPrivateURL() . $method . '?' . http_build_query( $req );
    $sign = hash_hmac( 'sha512', $uri, $secret );

    static $ch = null;
    if ( is_null( $ch ) ) {
      $ch = curl_init();
      curl_setopt( $ch, CURLOPT_RETURNTRANSFER, TRUE );
      curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, TRUE );
      curl_setopt( $ch, CURLOPT_USERAGENT, 'Mozilla/4.0 (compatible; Cryptsy API PHP client; ' . php_uname( 's' ) . '; PHP/' . phpversion() . ')' );
    }
    curl_setopt( $ch, CURLOPT_URL, $uri );
    curl_setopt( $ch, CURLOPT_HTTPHEADER, ["apisign: $sign" ] );
    curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 10 );
    curl_setopt( $ch, CURLOPT_TIMEOUT, 180 );

        curl_setopt($ch, CURLOPT_USERPWD, $key . ":" . $secret);  


    $error = null;
    for ( $i = 0; $i < 5; $i++ ) {
      try {
        $data = curl_exec( $ch );
        $code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
        if ($code != 200) {
          throw new Exception( "HTTP ${code} received from server" );
        }
        //

        if ( $data === false ) {
          $error = $this->prefix() . "Could not get reply: " . curl_error( $ch );
          logg( $error );
          continue;
        }

        return $this->xtractResponse( $data );
      }
      catch ( Exception $ex ) {
        $error = $ex->getMessage();
        logg( $this->prefix() . $error );

        if ( strpos( $error, 'ORDER_NOT_OPEN' ) !== false ||
             strpos( $error, 'MIN_TRADE_REQUIREMENT_NOT_MET' ) !== false ||
             strpos( $error, 'ADDRESS_GENERATING' ) !== false ||
             strpos( $error, '_OFFLINE' ) !== false ) {
          // Real error, don't attempt to retry needlessly.
          break;
        }

        // Refresh request parameters
        $nonce = $this->nonce();
        $req[ 'nonce' ] = sprintf( "%ld", $nonce );
        $uri = $this->getPrivateURL() . $method . '?' . http_build_query( $req );
        $sign = hash_hmac( 'sha512', $uri, $secret );
        curl_setopt( $ch, CURLOPT_URL, $uri );
        curl_setopt($ch, CURLOPT_USERPWD, $key . ":" . $secret);  
        continue;
      }
    }
    throw new Exception( $error );

  }

};

