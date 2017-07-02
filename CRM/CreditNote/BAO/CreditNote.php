<?php
/*
 +--------------------------------------------------------------------+
 | Creit Note Extension                                               |
 +--------------------------------------------------------------------+
 | Copyright (C) 2016-2017 JMA Consulting                             |
 +--------------------------------------------------------------------+
 | This file is a part of CiviCRM.                                    |
 |                                                                    |
 | CiviCRM is free software; you can copy, modify, and distribute it  |
 | under the terms of the GNU Affero General Public License           |
 | Version 3, 19 November 2007 and the CiviCRM Licensing Exception.   |
 |                                                                    |
 | CiviCRM is distributed in the hope that it will be useful, but     |
 | WITHOUT ANY WARRANTY; without even the implied warranty of         |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.               |
 | See the GNU Affero General Public License for more details.        |
 |                                                                    |
 | You should have received a copy of the GNU Affero General Public   |
 | License and the CiviCRM Licensing Exception along                  |
 | with this program; if not, contact CiviCRM LLC                     |
 | at info[AT]civicrm[DOT]org. If you have questions about the        |
 | GNU Affero General Public License or the licensing of CiviCRM,     |
 | see the CiviCRM license FAQ at http://civicrm.org/licensing        |
 +--------------------------------------------------------------------+
 */

/**
 *
 * @package CRM
 * @copyright JMA Consulting (c) 2016-2017
 * $Id$
 *
 */
class CRM_CreditNote_BAO_CreditNote extends CRM_Core_DAO {

  /**
   * Function will return the list of CN amounts.
   */ 
  public static function getCreditNotes() {
    $query = "SELECT ccm.id, ccm.contribution_id, ccm.amount + (-SUM(IFNULL(ccmp.amount, 0))) as amount, cc.currency
      FROM civicrm_creditnote_memo ccm
        INNER JOIN  civicrm_contribution cc ON cc.id = ccm.contribution_id
        LEFT JOIN civicrm_creditnote_memo_payment ccmp ON ccmp.creditnote_memo_id = ccm.id
      GROUP BY cc.id
    ";
    $cnPrefix = CRM_Contribute_BAO_Contribution::checkContributeSettings('credit_notes_prefix');
    $dao = CRM_Core_DAO::executeQuery($query);
    $creditNotes = array();
    while ($dao->fetch()) {
      if ($dao->amount <= 0) continue;
      $amount = CRM_Utils_Money::format($dao->amount, $dao->currency);
      $creditNotes[$dao->id] = "{$cnPrefix}{$dao->contribution_id} : {$amount}";
    }
    return $creditNotes;
  }

  public static function getCreditNoteAmount($creditNote) {
    if (!is_array($creditNote)) {
      $creditNote = array($creditNote);
    }
    $creditNote = implode(',', $creditNote);
    $query = "SELECT ccm.amount + (-SUM(IFNULL(ccmp.amount, 0))) as amount
      FROM civicrm_creditnote_memo ccm
        INNER JOIN  civicrm_contribution cc ON cc.id = ccm.contribution_id
        LEFT JOIN civicrm_creditnote_memo_payment ccmp ON ccmp.creditnote_memo_id = ccm.id
      WHERE ccm.id IN ($creditNote)
    ";
    return CRM_Core_DAO::singleValueQuery($query);
  }

  public static function addCreditNote($params) {
    $op = 'edit';
    $entityId = CRM_Utils_Array::value('id', $params);
    if (!$entityId) {
      $op = 'create';
    }
    CRM_Utils_Hook::pre($op, 'CreditNoteMemo', $entityId, $params);
    $creditNoteMemo = new CRM_CreditNote_DAO_CreditNoteMemo();
    $creditNoteMemo->copyValues($params);
    $creditNoteMemo->save();
    CRM_Utils_Hook::post($op, 'CreditNoteMemo', $creditNoteMemo->id, $creditNoteMemo);
    return $creditNoteMemo;
  }
  
  public static function addCreditNotePayments($params) {
    $op = 'edit';
    $entityId = CRM_Utils_Array::value('id', $params);
    if (!$entityId) {
      $op = 'create';
    }
    CRM_Utils_Hook::pre($op, 'CreditNoteMemoPayment', $entityId, $params);
    $creditNoteMemoPayment = new CRM_CreditNote_DAO_CreditNoteMemoPayment();
    $creditNoteMemoPayment->copyValues($params);
    $creditNoteMemoPayment->save();
    CRM_Utils_Hook::post($op, 'CreditNoteMemoPayment', $creditNoteMemoPayment->id, $creditNoteMemoPayment);
    return $creditNoteMemoPayment;
  }

  public static function createCreditNote() {
    $contributionId = CRM_Utils_Request::retrieve('contributionId', 'Positive');
    $error = FALSE;
    try {
      $alreadyCreated = self::checkIdCreditNoteCreated($contributionId);
      if ($alreadyCreated) {
        require_once 'api/Exception.php';
        throw new CiviCRM_API3_Exception(ts('Credit Note is already created for this contribution.'), 'undefined', array());
      }
      $contribution = civicrm_api3('Contribution', 'getSingle', array(
        'return' => array("total_amount", 'contribution_status_id'),
	'id' => $contributionId,
	'contribution_status_id' => array('IN' => array('Refunded', 'Pending refund')),
      ));
      $contributionStatus = CRM_Core_PseudoConstant::getName('CRM_Contribute_BAO_Contribution', 'contribution_status_id', $contribution['contribution_status_id']);
      if ($contributionStatus == 'Refunded') {
        $creditNoteAmount = $contribution['total_amount'];
      }
      else {
        $paidAmount = CRM_Core_BAO_FinancialTrxn::getPartialPaymentWithType($contributionId, 'contribution', TRUE, $contribution['total_amount']);
        $creditNoteAmount = CRM_Utils_Array::value('refund_due', $paidAmount);
      }
      if (!empty($creditNoteAmount)) {
        $params = array(
          'contribution_id' => $contributionId,
	  'created_date' => date('Ymd'),
	  'amount' => abs($creditNoteAmount),
        );
        self::addCreditNote($params);
      }
    }
    catch (CiviCRM_API3_Exception $e) {
      $error = TRUE;
    }
  }

  public static function checkIdCreditNoteCreated($contributionId) {
    return CRM_Core_DAO::getFieldValue('CRM_CreditNote_DAO_CreditNoteMemo', $contributionId, 'id', 'contribution_id');
  }

  public static function processCreditNote($params) {

  }

  public static function createCreditNotePayment($creditNotes, $financialTrxnId, $amount) {
    if (!is_array($creditNotes)) {
      $creditNotes = array($creditNotes);
    }
    $creditNotes = self::getCreditNoteDetails($creditNotes);
    foreach ($creditNotes as $creditNoteId => $creditNoteAmount) {
      $params = array(
        'creditnote_memo_id' => $creditNoteId,
	'financial_trxn_id' => $financialTrxnId,
      );
      if ($creditNoteAmount > $amount) {
        $params['amount'] = $amount;
      }
      else {
        $params['amount'] = $creditNoteAmount;
      }
      self::addCreditNotePayments($params);
      $amount -= $params['amount'];
      if ($amount <= 0) break;
    }
  }

  public static function getCreditNoteDetails ($creditNotes) {
    $creditNote = implode(',', $creditNotes);
    $query = "SELECT id, amount FROM (SELECT ccm.id, ccm.amount + (-SUM(IFNULL(ccmp.amount, 0))) as amount
      FROM civicrm_creditnote_memo ccm
        INNER JOIN  civicrm_contribution cc ON cc.id = ccm.contribution_id
        LEFT JOIN civicrm_creditnote_memo_payment ccmp ON ccmp.creditnote_memo_id = ccm.id
      WHERE ccm.id IN ($creditNote)
      GROUP BY cc.id) as temp WHERE amount > 0 ORDER BY amount
    ";
    $dao = CRM_Core_DAO::executeQuery($query);
    $creditNotes = array();
    while ($dao->fetch()) {
      if ($dao->amount <= 0) continue;
      $creditNotes[$dao->id] = $dao->amount;
    }
    return $creditNotes;
  }

}