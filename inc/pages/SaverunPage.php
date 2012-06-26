<?php
/**
 * "Saverun" page.
 *
 * @author Timo Tijhof, 2012
 * @since 0.1.0
 * @package TestSwarm
 */
class SaverunPage extends Page {

	public function execute() {
		$action = SaverunAction::newFromContext( $this->getContext() );
		$action->doAction();

		$this->setAction( $action );
		parent::execute();
	}

	protected function initContent() {
		$request = $this->getContext()->getRequest();

		$this->setRobots( 'noindex,nofollow' );

		/**
		 * action=saverun is used in 3 scenarios:
		 *
		 * - RunPage opens a testsuite (which includes inject.js), uses
		 *   window.opener.postMessage to contact run.js of the Runpage,
		 *   which then fires an AJAX request to api.php?action=saverun.
		 *
		 * - RunPage opens a testsuite (which includes inject.js), detects
		 *   postMessage is not supported, builds a <form> that POSTs to
		 *   SaverunPage (this page). From here we can access
		 *   window.opener.SWARM.runDone directly (which is underwise called as
		 *   SWARM.runDone from the onmessage event handler).
		 *
		 * - RunPage opens a testsuite, the test times out.
		 *   Handler in run.js closes the popup, and fires an AJAX request
		 *   to api.php?action=saverun to record the time out.
		 *
		 * In the first and last case api.php handles the the request.
		 * In the second case we can't use the API (due to cross-domain
		 * restrictions), so we cross-domain submit a form, and then
		 * output the following bit of HTML to contact the parent window.
		 */
		$script =
			'<script>'
			. 'if ( window.opener && window.opener.SWARM.runDone ) {'
			. 'window.opener.SWARM.runDone();'
			. '}'
			. '</script>';

		$html = '<p>This page is used as cross-domain form submission target to save test results in browsers'
			. ' that don\'t support <code>postMessage()</code>.</p>';

		if ( $request->wasPosted() ) {
			if ( $this->getAction()->getData() === 'ok' ) {
				$this->setTitle( 'Saved run!' );
				return $script . $html;
			}

			$this->setTitle( 'Saving run failed.' );
			return $script . $html;
		}

		// If someone visits SaverunPage directly,
		// just show an informative message.
		$this->setTitle( 'Save run' );
		return $html;
	}
}

