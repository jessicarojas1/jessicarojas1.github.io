<?php
/**
 * RSS 2.0 feed of published blog posts. Rendered with an XML content-type set
 * by the controller. All dynamic text is XML-escaped; bodies are wrapped in
 * CDATA with any nested ']]>' neutralised.
 */
$brand = Branding::name();
$xe = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
$cdata = static fn(?string $s): string => '<![CDATA[' . str_replace(']]>', ']]]]><![CDATA[>', (string)$s) . ']]>';
$feedTitle = $brand . ' — Blog';
$selfUrl = $base . '/blog/rss' . (!empty($_GET['space']) ? '?space=' . (int)$_GET['space'] : '');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">
  <channel>
    <title><?= $xe($feedTitle) ?></title>
    <link><?= $xe($base . '/blog') ?></link>
    <description><?= $xe('Latest published posts from ' . $brand) ?></description>
    <language>en</language>
    <lastBuildDate><?= date(DATE_RSS) ?></lastBuildDate>
    <atom:link href="<?= $xe($selfUrl) ?>" rel="self" type="application/rss+xml" />
    <?php foreach ($posts as $p):
      $when = $p['published_at'] ?: $p['created_at'];
      $link = $base . '/blog/' . (int)$p['id'];
      // Plain-text excerpt from the HTML body for <description>.
      $excerpt = trim(preg_replace('/\s+/', ' ', strip_tags((string)$p['body'])));
      if (mb_strlen($excerpt) > 400) $excerpt = mb_substr($excerpt, 0, 400) . '…';
    ?>
    <item>
      <title><?= $xe((string)$p['title']) ?></title>
      <link><?= $xe($link) ?></link>
      <guid isPermaLink="true"><?= $xe($link) ?></guid>
      <?php if (!empty($p['author_name'])): ?><dc:creator xmlns:dc="http://purl.org/dc/elements/1.1/"><?= $xe((string)$p['author_name']) ?></dc:creator><?php endif; ?>
      <?php if (!empty($p['space_name'])): ?><category><?= $xe((string)$p['space_name']) ?></category><?php endif; ?>
      <pubDate><?= $when ? date(DATE_RSS, strtotime((string)$when)) : date(DATE_RSS) ?></pubDate>
      <description><?= $cdata($excerpt) ?></description>
      <content:encoded xmlns:content="http://purl.org/rss/1.0/modules/content/"><?= $cdata((string)$p['body']) ?></content:encoded>
    </item>
    <?php endforeach; ?>
  </channel>
</rss>
