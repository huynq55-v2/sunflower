<?php

namespace Drupal\sunflower_lesson\Plugin\Action;

use Drupal\Core\Action\ActionBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\node\Entity\Node;
use Drupal\user\Entity\User;
use Drupal;

/**
 * @Action(
 *   id = "toggle_student_in_lesson",
 *   label = @Translation("Thêm hoặc gỡ học sinh khỏi buổi học hiện tại"),
 *   type = "user"
 * )
 */
class ToggleStudentInLesson extends ActionBase {

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $request = \Drupal::request();
    $session = $request->getSession();

    // Lấy lesson_nid từ route, query hoặc session Symfony.
    $nid = \Drupal::routeMatch()->getParameter('nid')
      ?? ($request->query->get('nid'))
      ?? $session->get('sunflower_current_lesson');

    // === Debug info ===
    \Drupal::logger('sunflower_lesson')->debug('buildConfigurationForm: route nid=@route, query nid=@query, session nid=@session, final nid=@nid', [
      '@route' => \Drupal::routeMatch()->getParameter('nid'),
      '@query' => $request->query->get('nid'),
      '@session' => $session->get('sunflower_current_lesson'),
      '@nid' => $nid,
    ]);

    $form['lesson_nid'] = [
      '#type' => 'hidden',
      '#value' => $nid,
    ];

    if (empty($nid)) {
      $form['warning'] = [
        '#markup' => '<div class="messages messages--warning">⚠️ Không xác định được buổi học. URL phải có dạng /student-by-lesson-management/[nid] hoặc ?nid=123.</div>',
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function execute($entity = NULL) {
    if (!$entity instanceof User) return;

    // Lấy lesson_nid trực tiếp từ form value.
    $lesson_nid = $this->configuration['lesson_nid'] ?? 0;

    if (!$lesson_nid || !is_numeric($lesson_nid)) {
      \Drupal::messenger()->addError('Không xác định được buổi học.');
      return;
    }

    $node = Node::load($lesson_nid);
    if (!$node || !$node->hasField('field_student_list')) {
      \Drupal::messenger()->addError('Buổi học không hợp lệ.');
      return;
    }

    $uids = array_column($node->get('field_student_list')->getValue(), 'target_id');
    $uid = $entity->id();
    $in_lesson = in_array($uid, $uids);

    // Toggle học sinh trong buổi học.
    $in_lesson ? $uids = array_diff($uids, [$uid]) : $uids[] = $uid;
    $node->set('field_student_list', array_map(fn($id) => ['target_id' => $id], $uids));
    $node->save();

    \Drupal::messenger()->addMessage(
      $in_lesson
        ? t('Đã gỡ học sinh @name khỏi buổi học.', ['@name' => $entity->getDisplayName()])
        : t('Đã thêm học sinh @name vào buổi học.', ['@name' => $entity->getDisplayName()])
    );

    // === Cập nhật field_is_in_lesson cho các node "account" ===
    $accounts = Drupal::entityTypeManager()
      ->getStorage('node')
      ->loadByProperties([
        'type' => 'account',
        'field_student' => $uid,
        'field_lesson' => $lesson_nid,
      ]);

    foreach ($accounts as $account_node) {
      if ($account_node->hasField('field_is_in_lesson')) {
        $account_node->set('field_is_in_lesson', $in_lesson ? 0 : 1);
        $account_node->save();
      }
    }

    if (empty($accounts)) {
      Drupal::logger('sunflower_lesson')->notice('Không tìm thấy Account tương ứng với User @uid trong buổi học @lesson.', [
        '@uid' => $uid,
        '@lesson' => $lesson_nid,
      ]);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE) {
    $account = $account ?: \Drupal::currentUser();
    $access = array_intersect(['administrator', 'manager'], $account->getRoles())
      ? AccessResult::allowed()
      : AccessResult::forbidden();
    return $return_as_object ? $access : $access->isAllowed();
  }
}
