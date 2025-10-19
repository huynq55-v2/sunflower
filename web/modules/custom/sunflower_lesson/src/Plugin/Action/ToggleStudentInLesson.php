<?php

namespace Drupal\sunflower_lesson\Plugin\Action;

use Drupal\Core\Action\ActionBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\node\Entity\Node;
use Drupal\user\Entity\User;

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
    $nid = NULL;

    // 1️⃣ Thử lấy từ route parameter
    $route_nid = \Drupal::routeMatch()->getParameter('nid');
    if (is_numeric($route_nid)) {
      $nid = (int) $route_nid;
    }

    // 2️⃣ Thử lấy từ query string ?nid=123
    if (!$nid && isset($_GET['nid']) && is_numeric($_GET['nid'])) {
      $nid = (int) $_GET['nid'];
    }

    // 3️⃣ Thử lấy từ session (nếu có)
    if (!$nid && isset($_SESSION['current_lesson_nid'])) {
      $nid = (int) $_SESSION['current_lesson_nid'];
    }

    // 4️⃣ Nếu vẫn không có thì lấy từ URL thô (cũ)
    if (!$nid) {
      $request_path = \Drupal::request()->getPathInfo();
      $parts = explode('/', trim($request_path, '/'));
      $last_part = end($parts);
      if (is_numeric($last_part)) {
        $nid = (int) $last_part;
      }
    }

    // 5️⃣ Lưu vào session để lần sau có thể dùng lại
    if ($nid) {
      $_SESSION['current_lesson_nid'] = $nid;
    }

    $form['lesson_nid'] = [
      '#type' => 'hidden',
      '#value' => $nid,
    ];

    if (empty($nid)) {
      $form['warning'] = [
        '#markup' => '<div class="messages messages--warning">⚠️ Không xác định được buổi học từ URL. Hãy đảm bảo URL có dạng /student-by-lesson-management/[nid] hoặc thêm ?nid=123.</div>',
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function execute($entity = NULL) {
    if (!$entity instanceof User) {
      return;
    }

    $lesson_nid = $this->configuration['lesson_nid'] ?? NULL;

    // Nếu lesson_nid vẫn trống, thử lấy lại từ session.
    if (!$lesson_nid && isset($_SESSION['current_lesson_nid'])) {
      $lesson_nid = $_SESSION['current_lesson_nid'];
    }

    if (!$lesson_nid || !is_numeric($lesson_nid)) {
      \Drupal::messenger()->addError('Không xác định được buổi học.');
      return;
    }

    $node = Node::load($lesson_nid);
    if (!$node || !$node->hasField('field_student_list')) {
      \Drupal::messenger()->addError('Buổi học không hợp lệ.');
      return;
    }

    $target_ids = array_column($node->get('field_student_list')->getValue(), 'target_id');
    $uid = $entity->id();

    if (in_array($uid, $target_ids)) {
      // Gỡ học sinh ra.
      $target_ids = array_diff($target_ids, [$uid]);
      \Drupal::messenger()->addMessage(t('Đã gỡ học sinh @name khỏi buổi học.', ['@name' => $entity->getDisplayName()]));
    } else {
      // Thêm học sinh vào buổi học.
      $target_ids[] = $uid;
      \Drupal::messenger()->addMessage(t('Đã thêm học sinh @name vào buổi học.', ['@name' => $entity->getDisplayName()]));
    }

    $node->set('field_student_list', array_map(fn($id) => ['target_id' => $id], $target_ids));
    $node->save();
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE) {
    // Nếu $account null thì lấy current user
    $account = $account ?: \Drupal::currentUser();

    // Roles (machine names)
    $allowed_roles = ['administrator', 'manager'];

    // Kiểm tra role
    $has_role = array_intersect($allowed_roles, $account->getRoles()) ? TRUE : FALSE;

    $access = $has_role ? AccessResult::allowed() : AccessResult::forbidden();
    return $return_as_object ? $access : $access->isAllowed();
  }

}
