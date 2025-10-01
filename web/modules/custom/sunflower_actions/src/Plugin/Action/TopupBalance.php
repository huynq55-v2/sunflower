<?php

namespace Drupal\sunflower_actions\Plugin\Action;

use Drupal\Core\Action\ConfigurableActionBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\user\UserInterface;
use Drupal\Core\Access\AccessResult;

/**
 * @Action(
 *   id = "sunflower_topup_balance",
 *   label = @Translation("Top up account balance for student"),
 *   type = "user"
 * )
 */
class TopupBalance extends ConfigurableActionBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'amount' => 0,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form['amount'] = [
      '#type' => 'number',
      '#title' => $this->t('Amount to top up'),
      '#min' => 1,
      '#step' => 1,
      '#required' => TRUE,
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $this->configuration['amount'] = $form_state->getValue('amount');
  }

  /**
   * {@inheritdoc}
   */
  public function execute($entity = NULL) {
    if ($entity instanceof UserInterface && $entity->hasField('field_account_balance')) {
      $amount = $this->configuration['amount'];
      $current = (float) $entity->get('field_account_balance')->value;
      $entity->set('field_account_balance', $current + $amount);
      $entity->save();

      // ✅ Ghi log vào content type Transaction log.
      $cashier = \Drupal::currentUser();
      // ✅ Chuẩn hóa thời gian theo ISO cho field DateTime.
        $field_date_value = \Drupal::service('date.formatter')
        ->format(\Drupal::time()->getRequestTime(), 'custom', 'Y-m-d\TH:i:s');

        $node = \Drupal\node\Entity\Node::create([
        'type' => 'transaction_log',
        'title' => 'Topup for ' . $entity->getAccountName(),
        'field_student' => $entity->id(),
        'field_amount' => $amount,
        'field_cashier' => $cashier->id(),
        'field_date' => $field_date_value,
        ]);
        $node->save();

    }
  }

  public function access($object, $account = NULL, $return_as_object = FALSE) {
    $account = $account ?: \Drupal::currentUser();

    if ($account->hasRole('cashier') || $account->hasRole('manager')) {
        return $return_as_object ? AccessResult::allowed() : TRUE;
    }

    return $return_as_object ? AccessResult::forbidden() : FALSE;
    }

}
