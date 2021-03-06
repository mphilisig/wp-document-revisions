<?php
/**
 * Unit tests for permalink and rewrite system.
 *
 * Permalink without trailing slash
 *
 * @author Benjamin J. Balter <ben@balter.com>
 * @package WP_Document_revisions
 */

/**
 * Test class for WP Document Revisions Rewrites.
 */
class Test_WP_Document_Revisions_Rewrites_Without extends WP_UnitTestCase {

	/**
	 * SetUp initial settings.
	 */
	public function setUp() {

		parent::setUp();

		// init permalink structure.
		global $wp_rewrite;
		$wp_rewrite->set_permalink_structure( '/%year%/%monthnum%/%day%/%postname%' );
		$wp_rewrite->flush_rules();

		$GLOBALS['is_wp_die'] = false;

		// init user roles.
		global $wpdr;
		$wpdr->add_caps();
		_flush_roles();

		// flush cache for good measure.
		wp_cache_flush();

	}

	/**
	 * Break down for next test.
	 */
	public function tearDown() {
		global $wp_rewrite;
		$wp_rewrite->set_permalink_structure( '' );

		_destroy_uploads();
		parent::tearDown();

	}

	/**
	 * Output message to log.
	 *
	 * @param string $text text to output.
	 */
	public function consoleLog( $text ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fwrite
			fwrite( STDERR, $text . "\n" );
	}

	/**
	 * Tests that a given URL actually returns the right file.
	 *
	 * @param string $url to check.
	 * @param string $file relative path of expected file.
	 * @param string $msg message describing failure.
	 */
	public function verify_download( $url = null, $file = null, $msg = null ) {

		if ( ! $url ) {
			return;
		}

		global $wpdr;
		flush_rewrite_rules();

		$this->go_to( $url );

		// verify contents are actually served.
		ob_start();
		$wpdr->serve_file( '' );
		$content = ob_get_contents();
		ob_end_clean();

		$this->assertFalse( is_404(), "404 ($msg)" );
		$this->assertFalse( _wpdr_is_wp_die(), "wp_died ($msg)" );
		$this->assertTrue( is_single(), "Not single ($msg)" );
		$this->assertStringEqualsFile( dirname( __FILE__ ) . '/' . $file, $content, "Contents don\'t match file ($msg)" );

	}


	/**
	 * Tests that a given url *DOES NOT* return a file.
	 *
	 * @param string $url to check.
	 * @param string $file relative path of expected file.
	 * @param string $msg message describing failure.
	 */
	public function verify_cant_download( $url = null, $file = null, $msg = null ) {

		if ( ! $url ) {
			return;
		}

		global $wpdr;

		flush_rewrite_rules();

		$this->go_to( $url );

		// verify contents are actually served.
		ob_start();
		$wpdr->serve_file( '' );
		$content = ob_get_contents();
		ob_end_clean();

		$this->assertTrue( ( is_404() || _wpdr_is_wp_die() ), "Not 404'd or wp_die'd ($msg)" );
		$this->assertStringNotEqualsFile( dirname( __FILE__ ) . '/' . $file, $content, "File being erroneously served ($msg)" );

	}


	/**
	 * Can the public access a public file? (yes).
	 */
	public function test_public_document() {
		global $wpdr;

		$this->consoleLog( 'Test_WP_Document_Revisions_Rewrites_Without - Start' );

		// make new public document.
		$tdr    = new Test_WP_Document_Revisions();
		$doc_id = $tdr->test_add_document();
		wp_publish_post( $doc_id );

		wp_set_current_user( 0 );
		wp_cache_flush();

		$this->verify_download( "?p=$doc_id&post_type=document", $tdr->test_file, 'Public Ugly Permalink' );
		$this->verify_download( get_permalink( $doc_id ), $tdr->test_file, 'Public Pretty Permalink' );

	}


	/**
	 * Can the public access a private file? (no).
	 */
	public function test_private_document_as_unauthenticated() {

		// make new private document.
		$tdr    = new Test_WP_Document_Revisions();
		$doc_id = $tdr->test_add_document();

		global $current_user;
		unset( $current_user );
		wp_set_current_user( 0 );
		wp_cache_flush();

		// public should be denied.
		$this->verify_cant_download( "?p=$doc_id&post_type=document", $tdr->test_file, 'Private, Unauthenticated Ugly Permalink' );
		$this->verify_cant_download( get_permalink( $doc_id ), $tdr->test_file, 'Private, Unauthenticated Pretty Permalink' );

	}


	/**
	 * Can a contributor access a public file? (no).
	 */
	public function test_private_document_as_contributor() {

		// make new private document.
		$tdr    = new Test_WP_Document_Revisions();
		$doc_id = $tdr->test_add_document();

		// contributor should be denied.
		$id = _make_user( 'contributor' );
		wp_set_current_user( $id );

		$this->verify_cant_download( "?p=$doc_id&post_type=document", $tdr->test_file, 'Private, Contrib. Ugly Permalink' );
		$this->verify_cant_download( get_permalink( $doc_id ), $tdr->test_file, 'Private, Contrib. Pretty Permalink' );
		_destroy_user( $id );

	}


	/**
	 * Can an admin access a private file? (yes).
	 */
	public function test_private_document_as_admin() {

		// make new private document.
		$tdr    = new Test_WP_Document_Revisions();
		$doc_id = $tdr->test_add_document();

		// admin should be able to access.
		$id   = _make_user( 'administrator' );
		$user = wp_set_current_user( $id );

		$this->verify_download( "?p=$doc_id&post_type=document", $tdr->test_file, 'Private, Admin Ugly Permalink' );
		$this->verify_download( get_permalink( $doc_id ), $tdr->test_file, 'Private, Admin Pretty Permalink' );
		_destroy_user( $id );

	}


	/**
	 * Can the public access a document revision? (no).
	 */
	public function test_document_revision_as_a_unauthenticated() {
		global $wpdr;

		// make new public, revised document.
		$tdr    = new Test_WP_Document_Revisions();
		$doc_id = $tdr->test_revise_document();
		wp_publish_post( $doc_id );
		$revisions = $wpdr->get_revisions( $doc_id );
		$revision  = array_pop( $revisions );

		global $current_user;
		unset( $current_user );
		wp_set_current_user( 0 );
		wp_cache_flush();

		// public should be denied access to revisions.
		$this->verify_cant_download( get_permalink( $revision->ID ), $tdr->test_file, 'Public revision request (pretty)' );
		$this->verify_cant_download( "?p=$doc_id&post_type=document&revision=1", $tdr->test_file, 'Public revision request (ugly)' );

	}


	/**
	 * Can an admin access a document revision? (yes).
	 */
	public function test_document_revision_as_admin() {

		global $wpdr;

		// make new public, revised document.
		$tdr    = new Test_WP_Document_Revisions();
		$doc_id = $tdr->test_revise_document();
		wp_publish_post( $doc_id );
		$revisions = $wpdr->get_revisions( $doc_id );
		$revision  = array_pop( $revisions );

		// admin should be able to access.
		$id = _make_user( 'administrator' );
		wp_set_current_user( $id );

		$this->markTestSkipped();
		$this->verify_download( get_permalink( $revision->ID ), $tdr->test_file, 'Admin revision clean' );
		$this->verify_download( "?p=$doc_id&post_type=document&revision=1", $tdr->test_file, 'Admin revision ugly' );
		_destroy_user( $id );

	}


	/**
	 * Do we serve the latest version of a document?
	 */
	public function test_revised_document() {

		global $wpdr;

		// make new public, revised document.
		$tdr    = new Test_WP_Document_Revisions();
		$doc_id = $tdr->test_revise_document();
		wp_publish_post( $doc_id );

		$this->verify_download( "?p=$doc_id&post_type=document", $tdr->test_file2, 'Revised, Ugly Permalink' );
		$this->verify_download( get_permalink( $doc_id ), $tdr->test_file2, 'Revised, Pretty Permalink' );

	}


	/**
	 * Does the document archive work?
	 */
	public function test_archive() {
		global $wpdr;
		$tdr    = new Test_WP_Document_Revisions();
		$doc_id = $tdr->test_add_document();
		flush_rewrite_rules();
		$this->go_to( get_home_url( null, $wpdr->document_slug() ) );
		$this->assertTrue( is_post_type_archive( 'document' ), 'Couldn\'t access /documents/' );
	}


	/**
	 * Does get_permalink generate the right permalink?
	 */
	public function test_permalink() {

		global $wpdr;
		$tdr       = new Test_WP_Document_Revisions();
		$doc_id    = $tdr->test_add_document();
		$doc       = get_post( $doc_id );
		$permalink = get_bloginfo( 'url' ) . '/' . $wpdr->document_slug() . '/' . gmdate( 'Y' ) . '/' . gmdate( 'm' ) . '/' . $doc->post_name . $wpdr->get_file_type( $doc_id );
		$this->assertEquals( $permalink, get_permalink( $doc_id ), 'Bad permalink' );

	}


	/**
	 * Test get_permalink() on a revision.
	 */
	public function test_revision_permalink() {

		global $wpdr;
		$tdr       = new Test_WP_Document_Revisions();
		$doc_id    = $tdr->test_revise_document();
		$revisions = $wpdr->get_revisions( $doc_id );
		$revision  = array_pop( $revisions );
		$permalink = get_bloginfo( 'url' ) . '/' . $wpdr->document_slug() . '/' . gmdate( 'Y' ) . '/' . gmdate( 'm' ) . '/' . get_post( $doc_id )->post_name . '-revision-1' . $wpdr->get_file_type( $doc_id );
		$this->assertEquals( $permalink, get_permalink( $revision->ID ), 'Bad revision permalink' );
	}

}
