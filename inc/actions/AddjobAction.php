<?php
/**
 * "Addjob" action.
 * Addjob ignores the current session. Instead it uses tokens, which (although
 * all registered users have an auth token in the database), only trusted
 * users know their own token.
 *
 * @author John Resig, 2008-2011
 * @author Timo Tijhof, 2012
 * @since 0.1.0
 * @package TestSwarm
 */
class AddjobAction extends Action {

	/**
	 * @actionMethod POST: Required.
	 * @actionParam jobName string: May contain HTML.
	 * @actionParam runMax int
	 * @actionParam runNames array
	 * @actionParam runUrls array
	 * @actionParam browserSets array
	 * @actionParam string authUsername
	 * @actionParam string authToken
	 * @actionAuth: Yes.
	 */
	public function doAction() {
		$conf = $this->getContext()->getConf();
		$db = $this->getContext()->getDB();
		$request = $this->getContext()->getRequest();

		$userId = $this->doRequireAuth();
		if ( !$userId ) {
			return;
		}

		$jobName = $request->getVal( "jobName" );
		$runMax = $request->getInt( "runMax" );
		$runNames = $request->getArray( "runNames" );
		$runUrls = $request->getArray( "runUrls" );
		$browserSets = $request->getArray( "browserSets" );

		if ( !$jobName
			|| !$runNames || !count( $runNames )
			|| !$runUrls || !count( $runUrls )
			|| !$browserSets || !count( $browserSets )
		) {
			$this->setError( "missing-parameters" );
			return;
		}

		if ( $runMax < 1 || $runMax > 99 ) {
			$this->setError( "invalid-input", "runMax must be a number between 1 and 99." );
			return;
		}

		$runs = array();

		// Loop through runNames / runUrls to validate them ahead of time,
		// and filter out empty ones from the AddjobPage.
		foreach ( $runNames as $runNr => $runName ) {
			if ( !isset( $runUrls[$runNr] ) ) {
				$this->setError( "invalid-input", "One or more runs is missing a URL." );
				return;
			}

			$runUrl = $runUrls[$runNr];

			// Filter out empty submissions,
			// AddjobPage may submit more input fields then filled in
			if ( $runUrl == '' && $runName == '' ) {
				continue;
			}

			if ( $runUrl == '' || $runName == '') {
				$this->setError( "invalid-input", "Run names and urls must be non-empty." );
				return;
			}

			if ( strlen( $runName ) > 255 ) {
				$formRunNr = $runNr + 1; // offset 0
				$this->setError( "invalid-input", "Run #{$formRunNr} name was too long (up to 255 characters)." );
				return;
			}

			$runs[] = array(
				"name" => $runName,
				"url" => $runUrl,
			);
		}

		if ( !count( $runs ) ) {
			$this->setError( 'missing-parameters', 'Job must have atleast 1 run.' );
			return;
		}

		// Generate a list of user agent IDs based on the selected browser sets
		$browserSetsCnt = count( $browserSets );
		$browserSets = array_unique( $browserSets );
		if ( $browserSetsCnt !== count( $browserSets ) ) {
			$this->setError( "invalid-input", "Duplicate entries in browserSets parameter." );
			return;
		}

		$uaIDs = array();

		foreach ( $browserSets as $browserSet ) {
			if ( !isset( $conf->browserSets->$browserSet ) ) {
				$this->setError( "invalid-input", "Unknown browser set: $browserSet." );
				return;
			}
			// Merge the arrays, and re-index with unique (prevents duplicate entries)
			$uaIDs = array_unique( array_merge( $uaIDs, $conf->browserSets->$browserSet ) );
		}

		if ( !count( $uaIDs ) ) {
			$this->setError( "data-corrupt", "No user agents matched the generated browserset filter." );
			return;
		}

		// Verify job name maxlength (otherwise MySQL will crop it, which might
		// result in incomplete html, screwing up the JobPage).
		if ( strlen( $jobName ) > 255 ) {
			$this->setError( "invalid-input", "Job name too long (up to 255 characters)." );
		}

		// Create job
		$isInserted = $db->query(str_queryf(
			"INSERT INTO jobs (user_id, name, created)
			VALUES (%u, %s, %s);",
			$userId,
			$jobName,
			swarmdb_dateformat( SWARM_NOW )
		));

		$newJobId = $db->getInsertId();
		if ( !$isInserted || !$newJobId ) {
			$this->setError( "internal-error", "Insertion of job into database failed." );
			return;
		}

		// Create all runs and schedule them for the wanted browsersets in run_useragent
		foreach ( $runs as $run ) {

			// Create this run
			$isInserted = $db->query(str_queryf(
				"INSERT INTO runs (job_id, name, url, created)
				VALUES(%u, %s, %s, %s);",
				$newJobId,
				$run['name'],
				$run['url'],
				swarmdb_dateformat( SWARM_NOW )
			));

			$newRunId = $db->getInsertId();

			if ( !$isInserted || !$newRunId ) {
				$this->setError( "internal-error", "Insertion of job into database failed." );
				return;
			}

			// Schedule run_useragent entries for all user agents matching
			// the browerset(s) for this job.
			foreach ( $uaIDs as $uaID ) {
				$isInserted = $db->query(str_queryf(
					"INSERT INTO run_useragent (run_id, useragent_id, max, updated, created)
					VALUES(%u, %s, %u, %s, %s);",
					$newRunId,
					$uaID,
					$runMax,
					swarmdb_dateformat( SWARM_NOW ),
					swarmdb_dateformat( SWARM_NOW )
				));
			}

		}

		$mostRecentSuccess = $db->getRow(str_queryf(
			"SELECT j.id
			FROM jobs j
			JOIN runs r ON j.id = r.job_id
			JOIN runresults rr on r.id = rr.run_id
			WHERE j.user_id = %u
			GROUP BY j.id
			HAVING MAX(rr.fail) = 0 AND MAX(rr.error) = 0
			ORDER BY j.id DESC LIMIT 1;",
			$userId
		));
		if ($mostRecentSuccess) {
			$mostRecentSuccess = $mostRecentSuccess->id;
		} else {
			$mostRecentSuccess = $newJobId;
		}
		$limit = $db->getRow(str_queryf(
			"SELECT id
			FROM jobs
			WHERE user_id = %u
			ORDER BY id DESC
			LIMIT " . ($conf->storage->numJobs - 1) . ",1;",
			$userId
		));
		if ($limit) {
			$db->query(str_queryf(
				"DELETE ru
				FROM jobs j
				LEFT JOIN runs r on j.id = r.job_id
				LEFT JOIN run_useragent ru on r.id = ru.run_id
				WHERE j.user_id = %u
				AND j.id != %u
				AND j.id < %u;",
				$userId,
				$mostRecentSuccess,
				$limit->id
			));
			$db->query(str_queryf(
				"DELETE r
				FROM jobs j
				LEFT JOIN runs r on j.id = r.job_id
				WHERE j.user_id = %u
				AND j.id != %u
				AND j.id < %u;",
				$userId,
				$mostRecentSuccess,
				$limit->id
			));
			$oldJobs = $db->getRows(str_queryf(
				"SELECT id, name
				FROM jobs
				WHERE user_id = %u
				AND id != %u
				AND id < %u;",
				$userId,
				$mostRecentSuccess,
				$limit->id
			));
			if ($oldJobs) {
				$nameRegex = "/^[a-zA-Z0-9 _.\\-]+$/";
				$projectName = $request->getVal( "authUsername" );
				$testDir = "";
				if ($conf->storage->testDir) {
					if (!preg_match($nameRegex, $projectName) || $projectName == "..") {
						error_log( "bad project name: " . $projectName );
					} else if (posix_getuid() == 0) {
						error_log( "refusing to clean testDir as root" );
					} else {
						$testDir = $conf->storage->testDir . "/" . $projectName. "/";
					}
				}
				foreach ( $oldJobs as $oldJob ) {
					$jobName = $oldJob->name;
					if ($testDir) {
						if (!preg_match($nameRegex, $jobName) || $jobName == "..") {
							error_log( "bad job name: " . $jobName );
						} else if (!is_dir($testDir . $jobName)) {
							error_log( "no such dir: " . $testDir . $jobName );
						} else {
							/* !!! C A U T I O N !!!
							!!! This is a dangerous operation. Use at your own risk.
							!!! It will delete the folder pointer to by
							!!! $testDir/$projectName/$jobName but a misconfiguration
							!!! or a malicious user can cause unexpected results,
							!!! althought the regex and uid checks above should limit
							!!! the potential damage.
							*/
							$error = shell_exec( "rm -fr '" . $testDir . $jobName . "'" );
							if ($error) {
								error_log($error);
							}
						}
					}
					$db->query(str_queryf(
						"DELETE FROM jobs
						WHERE id = %u",
						$oldJob->id
					));
				}
			}
		}

		$this->setData(array(
			"id" => $newJobId,
			"runTotal" => count( $runs ),
			"uaTotal" => count( $uaIDs ),
		));
	}
}
