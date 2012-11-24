<?php
/**
 * "Cleanup" action (previously WipeAction)
 *
 * @author John Resig, 2008-2011
 * @since 0.1.0
 * @package TestSwarm
 */
class CleanupAction extends Action {

	/**
	 * @actionNote This action takes no parameters.
	 */
	public function doAction() {
		$browserInfo = $this->getContext()->getBrowserInfo();
		$db = $this->getContext()->getDB();
		$conf = $this->getContext()->getConf();
		$request = $this->getContext()->getRequest();

		// As a security measure, only allow requests from localhost
		$ip = $request->getIP();
		if ($ip != "127.0.0.1" && $ip != "::1") {
			$this->setError( "unauthorized" );
			return false;
		}

		$resetTimedoutRuns = 0;

		// Get clients that are considered disconnected (not responding to the latest pings).
		// Then mark the runresults of its active runs as timed-out, and reset those runs so
		// they become available again for different clients in GetrunAction.

		$clientMaxAge = swarmdb_dateformat( time() - ( $conf->client->pingTime + $conf->client->pingTimeMargin ) );

		$rows = $db->getRows(str_queryf(
			"SELECT
				runresults.id as id
			FROM
				runresults
			INNER JOIN clients ON runresults.client_id = clients.id
			WHERE runresults.status = 1
			AND   clients.updated < %s;",
			$clientMaxAge
		));

		if ($rows) {
			$resetTimedoutRuns = count($rows);
			foreach ($rows as $row) {
				// Reset the run
				$db->query(str_queryf(
					"UPDATE run_useragent
					SET
						status = 0,
						results_id = NULL
					WHERE results_id = %u;",
					$row->id
				));

				// Update status of the result
				$db->query(str_queryf(
					"UPDATE runresults
					SET status = %s
					WHERE id = %u;",
					ResultAction::$STATE_LOST,
					$row->id
				));
			}
		}

		// Delete results that reference nonexistent runs
		$db->query(str_queryf(
			"DELETE FROM runresults
			WHERE run_id NOT IN (SELECT id FROM runs);"
		));
		// Delete clients that have no results and have not responded in the last 30 minutes
		$db->query(str_queryf(
			"DELETE FROM clients
			WHERE id NOT IN (SELECT DISTINCT(client_id) FROM runresults)
			AND updated < %s;",
			swarmdb_dateformat( time() - 30 * 60 )
		));
		// Delete users that are not registered and have no clients
		$db->query(str_queryf(
			"DELETE FROM users
			WHERE password = repeat('\\0', 40)
			AND auth = repeat('\\0', 40)
			AND id NOT IN (SELECT DISTINCT user_id FROM clients);"
		));

		$this->setData(array(
			"resetTimedoutRuns" => $resetTimedoutRuns,
		));
	}
}

