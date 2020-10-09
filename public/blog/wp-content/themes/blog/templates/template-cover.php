<?php
/**
 * Template Name: Cover Template
 * Template Post Type: post, page
 *
 * @package WordPress
 * @subpackage Twenty_Twenty
 * @since Twenty Twenty 1.0
 */

get_header();
?>

<div class="banner">
      <div class="container">
        <div class="row">
          <div class="col-lg-12">
            <div class="text-center">
              <h3>Notre blog</h3>
            </div>
          </div>
        </div>
        <div class="row">
        	<?php
    			global $post;
    			$myposts = get_posts( array(
			        'post_type'   => 'post',
				    'post_status' => 'publish',
				    'posts_per_page' => -1,
				    'fields' => 'ids'
			    ) );
			    if ( $myposts ) {
			    foreach ( $myposts as $post ) : 
			    setup_postdata( $post ); ?>
		        <div class="col-md-4 content">
		           <div class="single-blog-item">
	                    <div class="blog-thumnail">
	                        <a href="<?php the_permalink(); ?>"><?php the_post_thumbnail(); ?></a>
	                    </div>
	                    <div class="blog-content">
	                        <h4><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h4>
	                        <p><?php echo wp_trim_words( get_the_content(), 20, '...' ); ?></p>
	                        <a href="<?php the_permalink(); ?>" class="more-btn">Voir plus</a>
	                    </div>
	                    <span class="blog-date"><?php echo get_the_date( 'F j, Y' ); ?></span>
	                </div>
		        </div>
          	<?php
        		endforeach;
        		wp_reset_postdata();
    		} ?>
    		<div class="col-md-12">
    			<a href="#" id="seeMore">voir plus</a>
    			<a id="seeMoreNo" style="display: none;">Plus d'autres articles à afficher </a>
    		</div>
    	</div>
    </div>    
</div>

<?php get_footer(); ?>
