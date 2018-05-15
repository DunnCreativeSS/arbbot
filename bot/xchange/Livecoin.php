<?php

require_once __DIR__ . '/../CCXTAdapter.php';

define( 'LIVECOIN_ID', 15 );

class LivecoinExchange extends \ccxt\livecoin {

  use CCXTErrorHandler;

  public function nonce() {
    return generateNonce( LIVECOIN_ID );
  }

  public function describe() {

    $info = parent::describe();
    if ( !is_array( @$info[ 'api' ][ 'web' ][ 'post' ] ) ) {
      $info[ 'api' ][ 'web' ][ 'post' ] = array( );
    }
    // Define a private Livecoin API
    $info[ 'api' ][ 'web' ][ 'post' ][] = 'assetWithdraw/getAsset.html';

    return $info;

  }
};

class Livecoin extends CCXTAdapter {

  public function __construct() {
    $extraOptions = array(
      'recvWindow' => 15 * 1000, // Increase recvWindow to 15 seconds.
    );
    parent::__construct( LIVECOIN_ID, 'Livecoin', 'LivecoinExchange', $extraOptions );
  }

  public function getRateLimit() {
    return 1000;
  }

  public function isMarketActive( $market ) {
return true;
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
