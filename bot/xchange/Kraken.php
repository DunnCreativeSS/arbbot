<?php

require_once __DIR__ . '/../CCXTAdapter.php';

define( 'KRAKEN_ID',14 );

class KrakenExchange extends \ccxt\kraken {

  use CCXTErrorHandler;

  public function nonce() {
    return generateNonce( KRAKEN_ID );
  }

  public function describe() {

    $info = parent::describe();
    if ( !is_array( @$info[ 'api' ][ 'web' ][ 'post' ] ) ) {
      $info[ 'api' ][ 'web' ][ 'post' ] = array( );
    }
    // Define a private Kraken API
    $info[ 'api' ][ 'web' ][ 'post' ][] = 'assetWithdraw/getAsset.html';

    return $info;

  }
};

class Kraken extends CCXTAdapter {

  public function __construct() {
    $extraOptions = array(
      'recvWindow' => 15 * 1000, // Increase recvWindow to 15 seconds.
    );
    parent::__construct( KRAKEN_ID, 'Kraken', 'KrakenExchange', $extraOptions );
  }

  public function getRateLimit() {
    return 3000;
  }

  public function isMarketActive( $market ) {
    return $market[ 'active' ] || $market[ 'info' ][ 'status' ] == 'TRADING';
  }

  public function checkAPIReturnValue( $result ) {
    if ( isset( $result[ 'info' ][ 'code' ] ) ) {
      return false;
    }
    return $result[ 'info' ][ 'success' ] === true;
  }

  public function getDepositHistory() {

    return array(

      'history' => $this->exchange->wapiGetDepositHistory()[ 'depositList' ],
      'statusKey' => 'status',
      'coinKey' => 'asset',
      'amountKey' => 'amount',
      'timeKey' => 'insertTime',
      'pending' => 0 /* pending */,

    );

  }

  public function getWithdrawalHistory() {

    return array(

      'history' => $this->exchange->wapiGetWithdrawHistory()[ 'withdrawList' ],
      'statusKey' => 'status',
      'coinKey' => 'asset',
      'amountKey' => 'amount',
      'timeKey' => 'applyTime',
      'txidKey' => 'txId',
      'addressKey' => 'address',
      'pending' => [2 /* awaiting approval */, 4 /* processing */],
      'completed' => 6 /* completed */,

    );

  }

  public function getWithdrawLimits( $tradeable, $currency ) {

    $limits = $this->getLimits( $tradeable, $currency );
    $tradeableInternal = $this->coinNames[ $tradeable ];
    $minWithdraw = $this->exchange->webPostAssetWithdrawGetAssetHtml( array( 'asset' => $tradeableInternal ) );
    $limits[ 'amount' ][ 'min' ] = $minWithdraw[ 'minProductWithdraw' ];
    return $limits;

  }

};
