<?php
/**
 * Plugin Name: GO Last.fm
 * Plugin URI: https://janboddez.be/
 * Description: Display the cover art to the tracks you most recently listened to. 
 * Version: 0.2.1
 * Author: Jan Boddez
 * Author URI: https://janboddez.be/
 * Text Domain: go-lastfm
 * License: GPL v2
 */

/**
 * Widget class
 */
class GO_Lastfm_Widget extends WP_Widget {
	/**
	 * Constructor for the widget class
	 */
	function __construct() {
		parent::__construct(
			'go_lastfm_widget', 
			__( 'Last.fm', 'go-lastfm' ), 
			array( 'description' => __( 'Display the cover art to the tracks you most recently listened to.', 'go-lastfm' ) )
		);

		/*
		 * Load some basic styles; may or may not suffice for your website
		 */
		if ( is_active_widget( false, false, $this->id_base, true ) ) {
			wp_enqueue_style( 'go-lastfm', plugins_url( 'go-lastfm/go-lastfm.css' ) );
		}
	}

	/**
	 * Render the actual widget
	 */
	public function widget( $args, $instance ) {
		$title = apply_filters( 'widget_title', $instance['title'] );

		echo $args['before_widget'];

		if ( ! empty( $title ) ) {
			echo $args['before_title'] . $title . $args['after_title'];
		}

		/*
		 * Retrieve the album list stored previously (i.e. via a cron job); in
		 * this case, returns an array (!), or false
		 */
		$albums = get_option( 'go_lastfm_recent_albums' );

		if ( ! empty( $albums ) && is_array( $albums ) ) {
			/*
			 * Loop through the list and outputs the cover art and links to
			 * Last.fm.
			 */
			?>
			<div class="go-recent-albums">
				<ul>
					<?php foreach( $albums as $album ) : ?>
					<li>
						<?php if ( ! empty( $album['uri'] ) ) : ?>
							<a href="<?php echo esc_url( $album['uri'] ); ?>" title="<?php echo esc_attr( wptexturize( $album['title'] ) ); ?>" target="_blank" rel="noopener"><img src="<?php echo $album['thumbnail']; ?>" alt="<?php echo esc_attr( wptexturize( $album['title'] ) ); ?>" /></a>
						<?php else : ?>
							<img src="<?php echo $album['thumbnail']; ?>" title="<?php echo esc_attr( wptexturize( $album['title'] ) ); ?>" alt="<?php echo esc_attr( wptexturize( $album['title'] ) ); ?>" />
						<?php endif; ?>
					</li>
					<?php endforeach; ?>
				</ul>
			</div>
			<?php
		}

		echo $args['after_widget'];
	}

	/**
	 * Fairly generic 'Create Widget' form; allows for a custom widget title (or
	 * none at all)
	 */
	public function form( $instance ) {
		if ( isset( $instance[ 'title' ] ) ) {
			$title = $instance[ 'title' ];
		} else {
			$title = __( 'Last.fm', 'go-lastfm' );
		}
		?>
		<p>
			<label for="<?php echo $this->get_field_id( 'title' ); ?>"><?php _e( 'Title:', 'go-lastfm' ); ?></label> 
			<input class="widefat" id="<?php echo $this->get_field_id( 'title' ); ?>" name="<?php echo $this->get_field_name( 'title' ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>" />
		</p>
		<?php 
	}

	public function update( $new_instance, $old_instance ) {
		$instance = array();
		$instance['title'] = ( ! empty( $new_instance['title'] ) ) ? strip_tags( $new_instance['title'] ) : '';

		return $instance;
	}
}

/**
 * 'Main' plugin class and settings
 */
class GO_Lastfm {
	/**
	 * Constructor; register actions/hooks
	 */
	public function __construct() {
		register_activation_hook( __FILE__, array( $this, 'activate' ) );
		register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );

		add_action( 'admin_menu', array( $this, 'create_menu' ) );
		add_action( 'widgets_init', array( $this, 'load_widget' ) );
		add_action( 'daily_load_album_list', array( $this, 'load_album_list' ) );
	}

	/**
	 * Schedule the 'update album list' cron job
	 */
	public function activate() {
		$timestamp = wp_next_scheduled( 'daily_load_album_list' );

		if ( false === $timestamp ) {
			/*
			 * Note that this'll try to run '$this->load_album_list' on
			 * activation and thus before the Last.fm API settings are even
			 * entered. It'll then run but not return anything and continue to
			 * do so until the settings are correct.
			 * 
			 * To do: create a nicer solution, like schedule the cron job
			 * whenever the settings are modified.
			 */
			wp_schedule_event( time(), 'daily', 'daily_load_album_list' );
		}
	}

	/**
	 * Clear update schedule upon plugin deactivation
	 */
	public function deactivate() {
		wp_clear_scheduled_hook( 'daily_load_album_list' );
	}

	/**
	 * Actually register the widget defined earlier
	 */
	public function load_widget() {
		register_widget( 'GO_Lastfm_Widget' );
	}

	/**
	 * Update the album list by calling the Last.fm API; bulk of the 'logic'
	 */
	public function load_album_list() {
		/*
		 * Get Last.fm API settings
		 */
		$lastfm_api_key = get_option( 'go_lastfm_api_key' );
		$lastfm_user_name = get_option( 'go_lastfm_user_name' );

		$albums = array();

		/*
		 * Try retrieving the most recent track list, hiding/ignoring any errors
		 * that may pop up
		 */
		$response = @file_get_contents( 'http://ws.audioscrobbler.com/2.0/?method=user.getrecenttracks&user=' . $lastfm_user_name . '&limit=200&api_key=' . $lastfm_api_key . '&format=json' );
		$json = @json_decode( $response, true ); // Returns an associative array

		/*
		 * Check if that worked
		 */
		if ( is_array( $json['recenttracks']['track'] ) ) {
			/*
			 * We've somehow got back an array, so something must've gone right
			 */
			foreach ( $json['recenttracks']['track'] as $track ) {
				$title = /* $track['artist']['#text'] . ' - ' . */ trim( $track['album']['#text'] );
				$thumbnail = $track['image'][2]['#text']; // Album cover URL; may return an empty string
				$mbid = $track['album']['mbid']; // MusicBrainz album ID; may return an empty string

				// if ( ! $this->in_album_list( $thumbnail, $albums ) ) { // Won't work for compilation albums, as thumbnails aren't unique!
				/*
				 * Make sure to add an album only once; filtering by album name
				 * may lead to skipping an identically named album by a
				 * different artist, but we're okay with that
				 */
				if ( ! $this->in_album_list( $title, $albums ) ) {
					$uri = '';

					if ( ! empty( $mbid ) ) {
						/*
						 * MusicBrainz album ID known: now try and get the
						 * Last.fm URL for this album
						 */
						$response = @file_get_contents( 'http://ws.audioscrobbler.com/2.0/?method=album.getinfo&mbid=' . $mbid . '&api_key=' . $lastfm_api_key . '&format=json' );
						$json = @json_decode( $response, true ); // Returns an associative array

						if ( ! empty( $json['album']['url'] ) && filter_var( $json['album']['url'], FILTER_VALIDATE_URL ) ) {
							/*
							 * Valid URLs only, please
							 */
							$uri = $json['album']['url'];
						}
					} else {
						/*
						 * MusicBrainz album ID unknown: let's see if we can
						 * somehow try for a compilation album (this sometimes works)
						 */
						$response = @file_get_contents( 'http://ws.audioscrobbler.com/2.0/?method=album.getinfo&artist=Various+Artists&album=' . urlencode( $title ) . '&api_key=' . $lastfm_api_key . '&format=json' );
						$json = @json_decode( $response, true ); // Returns an associative array

						if ( ! empty( $json['album']['url'] ) && filter_var( $json['album']['url'], FILTER_VALIDATE_URL ) ) {
							/*
							 * We've somehow got back a valid URL, so let's
							 * store that
							 */
							$uri = $json['album']['url'];
						}

						if ( ! filter_var( $thumbnail, FILTER_VALIDATE_URL ) ) {
							/*
							 * Our previous query did not return a (valid) image
							 * URL for the track/album cover...
							 */
							if ( ! empty( $json['album']['image'][2]['#text'] ) && filter_var( $json['album']['image'][2]['#text'], FILTER_VALIDATE_URL ) ) {
								/*
								 * ...but maybe this one did? If so, let's store
								 * it 
								 */
								$thumbnail = $json['album']['image'][2]['#text'];
							}
						}
					}

					if ( ! filter_var( $thumbnail, FILTER_VALIDATE_URL ) ) {
						/*
						 * Still haven't found that image after all, skip this
						 * track (if there's nothing to display...)
						 */
						continue; // To the next item in the 'foreach' loop
					}

					$albums[] = array(
						'title' => $title,
						'uri' => $uri, // Note that this could still be an emptystring! That's okay, though
						'thumbnail' => $thumbnail,
					);
				}

				/*
				 * Limit the album count to 8
				 * 
				 * To do: make this a plugin setting
				 */
				if ( count( $albums ) >= 8 ) {
					break; // Break out of the 'foreach' loop entirely
				}
			}

			/*
			 * Pfew! Store the (updated) album list in the WordPress database
			 */
			update_option( 'go_lastfm_recent_albums', $albums ); // Accepts arrays, will add option if non-existent
		}
	}

	/**
	 * Register the plugin settings page
	 */
	public function create_menu() {
		add_options_page(
			__( 'Last.fm', 'go-lastfm' ),
			__( 'Last.fm', 'go-lastfm' ),
			'manage_options',
			'go-lastfm-settings',
			array( $this, 'settings_page' )
		);

		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	public function register_settings() {
		register_setting( 'go-lastfm-settings-group', 'go_lastfm_api_key' );
		register_setting( 'go-lastfm-settings-group', 'go_lastfm_user_name' );
	}

	/**
	 * Render the plugin options form
	 */
	public function settings_page() {
	?>
	<div class="wrap">
		<h1><?php _e( 'Last.fm', 'go-lastfm' ); ?></h1>
		<form method="post" action="options.php">
			<?php settings_fields( 'go-lastfm-settings-group' ); ?>
			<?php do_settings_sections( 'go-lastfm-settings-group' ); ?>
			<table class="form-table">
				<tr valign="top">
					<th scope="row"><?php _e( 'Last.fm API Key', 'go-lastfm' ); ?></th>
					<td><input class="widefat" type="text" name="go_lastfm_api_key" value="<?php echo esc_attr( get_option( 'go_lastfm_api_key' ) ); ?>" /></td>
				</tr>
				<tr valign="top">
					<th scope="row"><?php _e( 'Last.fm User Name', 'go-lastfm' ); ?></th>
					<td><input class="widefat" type="text" name="go_lastfm_user_name" value="<?php echo esc_attr( get_option( 'go_lastfm_user_name' ) ); ?>" /></td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
	</div>
	<?php
	}

	/**
	 * Search the '$albums' array for an entry with '$title'
	 */
	private function in_album_list( $title, $albums ) {
		foreach ( $albums as $album ) {
			/*
			 * 'Sanitizing' before comparing gets rid of possible weird
			 * capitalization and spacing issues
			 */
			if ( sanitize_title( $album['title'] ) === sanitize_title( $title ) ) {
				return true;
			}
		}

		return false;
	}
}

$go_lastfm = new GO_Lastfm();
