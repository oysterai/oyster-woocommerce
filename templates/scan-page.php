<?php
/**
 * Page template for the Oyster scan page.
 *
 * The theme's header and footer are kept, so the page still looks like the
 * merchant's shop. What it drops is the sidebar and the narrow blog column a
 * classic theme wraps a page in, neither of which belongs around a landing
 * page whose job is the scan.
 *
 * Classic themes only. A block theme lays its own pages out and has no sidebar
 * to shed, so Scan_Page_Template never routes one here.
 *
 * @package Oyster\Woo
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

get_header();

?>
<main id="oyster-scan-page" class="oyster-scan-page">
	<?php
	while ( have_posts() ) {
		the_post();
		?>
		<h1 class="oyster-scan-page-title"><?php the_title(); ?></h1>
		<?php
		the_content();
	}
	?>
</main>
<?php

get_footer();
