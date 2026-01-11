<?php
if (!defined('ABSPATH')) exit;

function kkchat_admin_rooms_page() {
  if (!current_user_can('manage_options')) return;
  global $wpdb; $t = kkchat_tables();

  if (isset($_POST['kk_add_room'])) {
    check_admin_referer('kkchat_rooms');
    $slug  = kkchat_sanitize_room_slug($_POST['slug'] ?? '');
    $title = sanitize_text_field($_POST['title'] ?? '');
    $mo    = !empty($_POST['member_only']) ? 1 : 0;
    $sort  = (int)($_POST['sort'] ?? 100);

    if (!$slug || !$title) {
      echo '<div class="error"><p>Slug och titel krävs.</p></div>';
    } else {
      $exists = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t['rooms']} WHERE slug=%s", $slug));
      if ($exists) {
        echo '<div class="error"><p>Ett rum med den slugen finns redan.</p></div>';
      } else {
        $ok = $wpdb->insert(
          $t['rooms'],
          ['slug'=>$slug, 'title'=>$title, 'member_only'=>$mo, 'sort'=>$sort],
          ['%s','%s','%d','%d']
        );
        if ($ok === false) {
          $err = $wpdb->last_error ? esc_html($wpdb->last_error) : 'Okänt databasfel.';
          echo '<div class="error"><p>Kunde inte spara rum: '.$err.'</p></div>';
        } else {
          echo '<div class="updated"><p>Rummet sparat.</p></div>';
        }
      }
    }
  }

  if (isset($_GET['kk_del_room'])) {
    $slug = kkchat_sanitize_room_slug($_GET['kk_del_room']);
    if ($slug && check_admin_referer('kkchat_rooms_del_'.$slug)) {
      $wpdb->delete($t['rooms'], ['slug'=>$slug], ['%s']);
      echo '<div class="updated"><p>Rummet borttaget.</p></div>';
    }
  }

  $rooms = $wpdb->get_results("SELECT * FROM {$t['rooms']} ORDER BY sort ASC, title ASC");
  ?>
  <div class="wrap">
    <h1>KKchat – Rum</h1>
    <h2>Lägg till / Redigera</h2>
    <form method="post"><?php wp_nonce_field('kkchat_rooms'); ?>
      <table class="form-table">
        <tr><th><label for="slug">Slug</label></th><td><input name="slug" id="slug" required class="regular-text" placeholder="t.ex. general, klubb, vip"></td></tr>
        <tr><th><label for="title">Titel</label></th><td><input name="title" id="title" required class="regular-text" placeholder="Synligt rumsnamn"></td></tr>
        <tr><th><label for="sort">Sortering</label></th><td><input name="sort" id="sort" type="number" value="100" class="small-text"> (lägre = först)</td></tr>
        <tr><th>Endast medlemmar</th><td><label><input type="checkbox" name="member_only" value="1"> Endast inloggade WP-medlemmar (inga gäster)</label></td></tr>
      </table>
      <p><button class="button button-primary" name="kk_add_room" value="1">Spara rum</button></p>
    </form>

    <h2>Befintliga rum</h2>
    <table class="widefat striped">
      <thead><tr><th>Slug</th><th>Titel</th><th>Endast medlemmar</th><th>Sortering</th><th>Åtgärder</th></tr></thead>
      <tbody>
      <?php if ($rooms): foreach ($rooms as $r): ?>
        <tr>
          <td><?php echo esc_html($r->slug); ?></td>
          <td><?php echo esc_html($r->title); ?></td>
          <td><?php echo $r->member_only ? 'Ja' : 'Nej'; ?></td>
          <td><?php echo (int)$r->sort; ?></td>
          <td>
            <?php
              $rooms_base = menu_page_url('kkchat_rooms', false);
              $del_url = wp_nonce_url(add_query_arg('kk_del_room', $r->slug, $rooms_base), 'kkchat_rooms_del_'.$r->slug);
              $conf = sprintf('Ta bort rummet &quot;%s&quot;? Meddelanden finns kvar.', esc_js($r->title));
            ?>
            <a href="<?php echo esc_url($del_url); ?>" class="button button-secondary" onclick="return confirm('<?php echo $conf; ?>');">Ta bort</a>
          </td>
        </tr>
      <?php endforeach; else: ?>
        <tr><td colspan="5">Inga rum ännu.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php
}
