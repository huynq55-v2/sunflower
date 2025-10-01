<?php

namespace Drupal\classroom_scheduler\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\node\Entity\Node;
use Symfony\Component\HttpFoundation\Response;

class ClassroomCronController extends ControllerBase {

  public function run($key) {
    // 🔒 Bảo vệ bằng secret key.
    $secret = 'Z9rsNn3G9mF5Nw7aDemnGPz8WuGdRRBs'; // TODO: đưa vào config/site settings.
    if ($key !== $secret) {
      return new Response('Access denied', 403);
    }

    // Thời gian hiện tại
    $now = new \DateTime('now', new \DateTimeZone('Asia/Ho_Chi_Minh'));
    $weekday = strtolower($now->format('l')); // monday, tuesday...
    $time    = $now->format('H:i');           // 08:30
    $study_date = $now->format('Y-m-d');

    $output = "Checking at {$weekday} {$study_date} {$time}\n";

    // Load tất cả node classroom
    $nids = \Drupal::entityQuery('node')
      ->condition('type', 'classroom')
      ->accessCheck(FALSE)
      ->execute();

    $nodes = Node::loadMultiple($nids);

    foreach ($nodes as $classroom) {
      // Duyệt Paragraph field_classroom_study_time
      $paragraphs = $classroom->get('field_classroom_study_time')->referencedEntities();
      foreach ($paragraphs as $schedule) {
        // Lấy weekday từ paragraph
        $day = strtolower($schedule->get('field_day_of_week')->value);

        // Lấy giờ phút từ field_study_time (Date)
        $datetime_value = $schedule->get('field_study_time')->value;
        $hour = '';
        if ($datetime_value) {
        // 1. Tạo đối tượng DateTime và chỉ rõ nó đang ở múi giờ UTC.
        $dt_utc = new \DateTime($datetime_value, new \DateTimeZone('UTC'));
        
        // 2. Chuyển đổi đối tượng DateTime đó sang múi giờ 'Asia/Ho_Chi_Minh'.
        $dt_utc->setTimezone(new \DateTimeZone('Asia/Ho_Chi_Minh'));
        
        // 3. Bây giờ mới format để lấy giờ đúng.
        $hour = $dt_utc->format('H:i');
        }

        // So khớp: hôm nay đúng thứ và đúng giờ
        if ($day === $weekday && $hour === $time) {

          // Tránh tạo trùng
          $exists = \Drupal::entityQuery('node')
            ->condition('type', 'lesson')
            ->condition('field_classroom', $classroom->id())
            ->condition('field_study_date', $study_date)
            ->condition('field_study_time', $hour)
            ->accessCheck(FALSE)
            ->count()
            ->execute();

          if ($exists) {
            $output .= "Lesson already exists for {$classroom->label()} {$study_date} {$hour}\n";
            continue;
          }

          // Tạo buổi học (lesson)
          $lesson = Node::create([
            'type' => 'lesson',
            'title' => $classroom->label() . " - {$study_date} {$hour}",
            'field_classroom' => $classroom->id(),
            'field_study_date' => $study_date,
            'field_study_time' => $hour,
          ]);
          $lesson->save();

          $output .= "✅ Created lesson for {$classroom->label()} at {$study_date} {$hour}\n";
        }
      }
    }

    return new Response(nl2br($output));
  }
}
