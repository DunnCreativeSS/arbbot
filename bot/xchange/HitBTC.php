<?php

require_once __DIR__ . '/../Config.php';
require_once __DIR__ . '/../HitBTCLikeExchange.php';

class HitBTC extends HitBTCLikeExchange {

  const ID = 7;
  //
  const PUBLIC_URL = 'https://api.HitBTC.com/api/2/public/';
  const PRIVATE_URL = 'https://api.HitBTC.com/api/2/';

  private $fullOrderHistory = null;

  function __construct() {
    parent::__construct( Config::get( "HitBTC.key" ), Config::get( "HitBTC.secret" ),
                         array(
      'separator' => '',
    ) );

  }

  public function addFeeToPrice( $price, $tradeable, $currency ) {
    return $price * 1.0025;

  }

  public function deductFeeFromAmountBuy( $amount, $tradeable, $currency ) {
    return $amount * 0.9975;

  }

  public function deductFeeFromAmountSell( $amount, $tradeable, $currency ) {
    return $amount * 0.9975;

  }

  public function getFilledOrderPrice( $type, $tradeable, $currency, $id ) {
    $market = $currency . '' . $tradeable;
    $result = $this->queryAPI( 'history/order', [ 'symbol' => $market ] );
    $id = trim( $id, '{}' );

    foreach ($result as $order) {
      if ($order[ 'clientOrderId' ] == $id) {
        
        $factor = ($type == 'sell') ? -1 : 1;
        return $order[ 'price' ] + $factor;
      }
    }
    return null;
  }

  public function queryTradeHistory( $options = array( ), $recentOnly = false ) {
    $results = array( );

    $type_map = array(
      'LIMIT_BUY' => 'buy',
      'LIMIT_SELL' => 'sell',
    );

    if (!$recentOnly && $this->fullOrderHistory !== null) {
      $results = $this->fullOrderHistory;
    } else if (!$recentOnly && !$this->fullOrderHistory &&
               file_exists( __DIR__ . '/../../HitBTC-fullOrders.csv' )) {
      $file = file_get_contents( __DIR__ . '/../../HitBTC-fullOrders.csv' );
      $file = iconv( 'utf-16', 'utf-8', $file );
      $lines = explode( "\r\n", $file );
      $first = true;
      foreach ($lines as $line) {
        if ($first) {
          // Ignore the first line.
          $first = false;
          continue;
        }
        $data = str_getcsv( $line );
        if (count( $data ) != 9) {
          continue;
        }
	$market = $data[ 1 ];
	$arr = explode( '-', $market );
	$currency = $arr[ 0 ];
	$tradeable = $arr[ 1 ];
	$market = "${currency}${tradeable}";
	$amount = $data[ 3 ];
	$feeFactor = ($data[ 2 ] == 'LIMIT_SELL') ? -1 : 1;
	$results[ $market ][] = array(
	  'rawID' => $data[ 0 ],
	  'id' => $data[ 0 ],
	  'currency' => $currency,
	  'tradeable' => $tradeable,
	  'type' => $type_map[ $data[ 2 ] ],
	  'time' => strtotime( $data[ 7 ] ),
	  'rate' => $data[ 6 ] / $amount,
	  'amount' => $amount,
	  'fee' => $feeFactor * $data[ 5 ],
	  'total' => $data[ 6 ],
	);
      }
      $this->fullOrderHistory = $results;
    }

    $result = $this->queryAPI( 'history/order' );
    logg($result);
    $checkArray = !empty( $result );

      if (!empty($result)) {
    foreach ($result as $row) {
      $market = $row[ 'Exchange' ];
      $arr = explode( '-', $market );
      $currency = $arr[ 0 ];
      $tradeable = $arr[ 1 ];
      $market = "${currency}${tradeable}";
      if (!in_array( $market, array_keys( $results ) )) {
        $results[ $market ] = array();
      }
      $amount = $row[ 'quantity' ];

        $seen = false;
        foreach ($results[ $market ] as $item) {
          if ($item[ 'rawID' ] == $row[ 'clientOrderId' ]) {
            // We have already recorder this ID.
            $seen = true;
            break;
          }
        }
        if ($seen) {
          continue;
        }

      $results[ $market ][] = array(
        'rawID' => $row[ 'clientOrderId' ],
        'id' => $row[ 'clientOrderId' ],
        'currency' => $currency,
        'tradeable' => $tradeable,
        'type' => $type_map[ $row[ 'type' ] ],
        'time' => strtotime( $row[ 'createdAt' ] ),
        'rate' => $row[ 'price' ],
        'amount' => $amount,
        'total' => $row[ 'price' ],
      );
    }
      }

    foreach ( array_keys( $results ) as $market ) {
      usort( $results[ $market ], 'compareByTime' );
    }

    return $results;
  }

  public function cancelOrder( $orderID ) {

    try {
      $this->queryCancelOrder( $orderID );
      return true;
    }
    catch ( Exception $ex ) {
      if ( strpos( $ex->getMessage(), 'ORDER_NOT_OPEN' ) === false ) {
	logg( $this->prefix() . "Got an exception in cancelOrder(): " . $ex->getMessage() );
	return true;
      }
      return false;
    }

  }

  public function withdrawSupportsTag() {

    return true;

  }

  protected function queryWithdraw( $coin, $amount, $address, $tag ) {
    $options = [
      'currency' => $coin,
      'quantity' => formatBTC( $amount ),
      'address' => $address
    ];
    if ( !is_null( $tag ) ) {
      $options[ 'paymentid' ] = $tag;
    }
    return $this->queryAPI( 'account/withdraw', $options );

  }

  public function getSmallestOrderSize( $tradeable, $currency, $type ) {

    // TODO: Use MinTradeSize (see refreshExchangeData)
    return '0.00100000';

  }

  public function getID() {

    return HitBTC::ID;

  }

  public function getName() {

    return "HitBTC";

  }

  public function getTradeHistoryCSVName() {

    return "HitBTC-fullOrders.csv";

  }

  protected function getPublicURL() {

    return HitBTC::PUBLIC_URL;

  }

  protected function getPrivateURL() {

    return HitBTC::PRIVATE_URL;

  }

}
