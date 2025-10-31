<?php

namespace Drupal\sunflower_classroom\Plugin\Action;

use Drupal\Core\Action\ActionBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\node\Entity\Node;
use Drupal\user\Entity\User;
use Drupal;

/**
 * @Action(
 *   id = "toggle_student_in_class",
 *   label = @Translation("Thêm hoặc gỡ học sinh khỏi lớp hiện tại"),
 *   type = "user"
 * )
 */
class ToggleStudentInClass extends ActionBase {

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $request = \Drupal::request();
    $session = $request->getSession();

    // Lấy class_nid từ route, query, hoặc session Symfony
    $nid = \Drupal::routeMatch()->getParameter('nid')
      ?? ($request->query->get('nid'))
      ?? $session->get('sunflower_current_class');

    // === Debug info ===
    \Drupal::logger('sunflower_classroom')->debug('buildConfigurationForm: route nid=@route, query nid=@query, session nid=@session, final nid=@nid', [
      '@nid' => $nid,
    ]);
    
    $form['class_nid'] = ['#type' => 'hidden', '#value' => $nid];

    if (empty($nid)) {
      $form['warning'] = [
        '#markup' => '<div class="messages messages--warning">⚠️ Không xác định được lớp học. URL phải có dạng /student-by-class-management/[nid] hoặc ?nid=123.</div>',
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function execute($entity = NULL) {
    if (!$entity instanceof User) return;

    // Lấy class_nid trực tiếp từ form value
    $class_nid = $this->configuration['class_nid'] ?? 0;

    if (!$class_nid || !is_numeric($class_nid)) {
      \Drupal::messenger()->addError('Không xác định được lớp học.');
      return;
    }

    $node = Node::load($class_nid);
    if (!$node || !$node->hasField('field_student_list')) {
      \Drupal::messenger()->addError('Lớp học không hợp lệ.');
      return;
    }

    $uids = array_column($node->get('field_student_list')->getValue(), 'target_id');
    $uid = $entity->id();
    $in_class = in_array($uid, $uids);

    // Toggle
    $in_class ? $uids = array_diff($uids, [$uid]) : $uids[] = $uid;
    $node->set('field_student_list', array_map(fn($id) => ['target_id' => $id], $uids));
    $node->save();

    \Drupal::messenger()->addMessage(
      $in_class
        ? t('Đã gỡ học sinh @name khỏi lớp.', ['@name' => $entity->getDisplayName()])
        : t('Đã thêm học sinh @name vào lớp.', ['@name' => $entity->getDisplayName()])
    );

    // Cập nhật field_is_in_class
    $accounts = Drupal::entityTypeManager()
      ->getStorage('node')
      ->loadByProperties([
        'type' => 'account',
        'field_student' => $uid,
        'field_classroom' => $class_nid,
      ]);

    foreach ($accounts as $account_node) {
      if ($account_node->hasField('field_is_in_class')) {
        $account_node->set('field_is_in_class', $in_class ? 0 : 1);
        $account_node->save();
      }
    }

    if (empty($accounts)) {
      Drupal::logger('classroom')->notice('Không tìm thấy Account tương ứng với User @uid trong lớp @class.', [
        '@uid' => $uid,
        '@class' => $class_nid,
      ]);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE) {
    $account = $account ?: \Drupal::currentUser();
    $access = array_intersect(['administrator','manager'], $account->getRoles()) 
      ? AccessResult::allowed() 
      : AccessResult::forbidden();
    return $return_as_object ? $access : $access->isAllowed();
  }
}
