<?php

namespace Drupal\sunflower_actions\Plugin\Action;

use Drupal\Core\Action\ConfigurableActionBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\node\Entity\Node;

/**
 * @Action(
 *   id = "sunflower_topup_account_balance",
 *   label = @Translation("Top up or deduct account balance (Account node)"),
 *   type = "node"
 * )
 */
class TopupAccountBalance extends ConfigurableActionBase {

  public function defaultConfiguration() {
    return [
      'amount' => 0,
      'reason' => '',
    ];
  }

  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form['amount'] = [
      '#type' => 'number',
      '#title' => $this->t('Amount to add (use negative number to deduct)'),
      '#description' => $this->t('Enter a positive number to top up, or negative to deduct from the account.'),
      '#step' => 1,
      '#required' => TRUE,
    ];

    $form['reason'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Reason'),
      '#description' => $this->t('Explain why this transaction is made.'),
      '#required' => TRUE,
      '#maxlength' => 255,
    ];

    return $form;
  }

  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $this->configuration['amount'] = $form_state->getValue('amount');
    $this->configuration['reason'] = $form_state->getValue('reason');
  }

  public function execute($entity = NULL) {
    // Only work on account nodes.
    if (!$entity instanceof Node || $entity->bundle() !== 'account') {
      return;
    }

    $amount = (float) $this->configuration['amount'];
    $reason = trim($this->configuration['reason']);
    $cashier = \Drupal::currentUser();

    // Prepare an ISO timestamp string for field_date and created time.
    $now = \Drupal::time()->getRequestTime();
    $field_date_value = \Drupal::service('date.formatter')
      ->format($now, 'custom', 'Y-m-d\TH:i:s');

    // 1) Create the transaction_log node (do NOT pre-change account->field_balance here).
    $log = Node::create([
      'type' => 'transaction_log',
      'title' => sprintf('%s for %s (%+.0f)', ($amount >= 0 ? 'Topup' : 'Deduct'), $entity->label(), $amount),
      'field_account' => ['target_id' => $entity->id()],
      'field_amount' => $amount,
      'field_cashier' => ['target_id' => $cashier->id()],
      'field_date' => $field_date_value,
      'field_topup_reason' => $reason,
    ]);

    // Set created time to match field_date (so ordering by effective time will work).
    $ts = strtotime($field_date_value) ?: $now;
    $log->setCreatedTime($ts);
    $log->save();

    // 2) Attach to account->field_transactions if exists (pre-save)
    if ($entity->hasField('field_transactions')) {
      $transactions = $entity->get('field_transactions')->getValue();
      // avoid duplicates
      $exists = FALSE;
      foreach ($transactions as $t) {
        if (!empty($t['target_id']) && $t['target_id'] == $log->id()) {
          $exists = TRUE;
          break;
        }
      }
      if (!$exists) {
        $transactions[] = ['target_id' => $log->id()];
        $entity->set('field_transactions', $transactions);
        $entity->save();
      }
    }

    // 3) Recalculate entire account history so field_balance_after is correct for ALL txns.
    if (function_exists('sunflower_recalculate_account_balance')) {
      sunflower_recalculate_account_balance($entity);
    } else {
      \Drupal::logger('sunflower_actions')->warning('sunflower_recalculate_account_balance() not found — please make sure it is declared and autoloaded.');
    }

    // Feedback
    \Drupal::messenger()->addStatus(t('✅ %amount applied to account %account. Recalculated history.', [
      '%amount' => sprintf('%+.0f', $amount),
      '%account' => $entity->label(),
    ]));
  }

  public function access($object, $account = NULL, $return_as_object = FALSE) {
    $account = $account ?: \Drupal::currentUser();
    $allowed = $account->hasRole('cashier') || $account->hasRole('manager');
    return $return_as_object ? AccessResult::allowedIf($allowed) : $allowed;
  }
}
