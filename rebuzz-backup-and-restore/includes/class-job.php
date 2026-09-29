<?php

if (!defined('ABSPATH')) {
    exit;
}

class WPCB_Job
{
    private $id;
    private $data = [];

    public function __construct($id = '')
    {
        if (empty($id)) {
            $id = uniqid('wpcb_', true);
        }

        $this->id = $id;

        $this->load();
    }

    public function id()
    {
        return $this->id;
    }

    private function key()
    {
        return 'wpcb_job_' . md5($this->id);
    }

    private function load()
    {
        $this->data = get_transient($this->key());

        if (!is_array($this->data)) {

            $this->data = [
                'status' => 'pending',
                'step' => 0,
                'progress' => 0,
                'message' => '',
                'created' => current_time('mysql'),
                'started' => time(),
                'elapsed' => 0,
                'eta' => null,
                // job owner; isOwnedBy() uses this so admins can't poll others' jobs
                'owner' => get_current_user_id()
            ];

            $this->save();
        }
    }

    public function save()
    {
        set_transient(
            $this->key(),
            $this->data,
            HOUR_IN_SECONDS
        );
    }

    public function update(array $values)
    {
        $this->data = array_merge(
            $this->data,
            $values
        );

        // Last sign of life; wpcb_backup_lock_check() uses it to spot a dead job.
        $this->data['heartbeat'] = time();

        $this->refreshTiming();

        $this->save();
    }

    /**
     * Recompute elapsed time + rough ETA; runs on every update()
     * so backup/restore jobs get timing for free.
     */
    private function refreshTiming()
    {
        if (empty($this->data['started'])) {
            $this->data['started'] = time();
        }

        $elapsed = time() - (int) $this->data['started'];

        if ($elapsed < 0) {
            $elapsed = 0;
        }

        $this->data['elapsed'] = $elapsed;

        $progress = isset($this->data['progress'])
            ? (float) $this->data['progress']
            : 0;

        $status = isset($this->data['status'])
            ? $this->data['status']
            : '';

        if ($progress > 0 && $progress < 100 && $status === 'running') {

            $rate = $elapsed / $progress;

            $this->data['eta'] = (int) round(
                $rate * (100 - $progress)
            );

        } else {

            $this->data['eta'] = 0;

        }
    }

    public function get()
    {
        return $this->data;
    }

    /**
     * Job state safe for the browser - strips internal-only fields
     * (preserved admin creds, server paths) that must stay server-side.
     */
    public function getPublic()
    {
        $data = $this->data;

        unset(
            $data['preserve_admin_pass'],
            $data['preserve_admin_login'],
            $data['zip'],
            $data['workspace'],
            $data['token']
        );

        return $data;
    }

    /**
     * True if $userId started this job. Jobs with no 'owner' (older,
     * pre-check) are treated as everyone's - they expire within the hour anyway.
     */
    public function isOwnedBy($userId)
    {
        if (!array_key_exists('owner', $this->data)) {
            return true;
        }

        return (int) $this->data['owner'] === (int) $userId;
    }

    public function delete()
    {
        delete_transient($this->key());
    }

    /** Whether a job with this ID exists. Unlike the constructor, never creates one. */
    public static function exists($id)
    {
        return $id !== '' && is_array(get_transient('wpcb_job_' . md5($id)));
    }

    /**
     * Blocks duplicate concurrent requests for the same job (e.g. a
     * client retry firing while a slow chunk is still running server-side)
     * from racing on shared state like session data. Not race-free,
     * just good enough to reject the common overlap case.
     */
    public function isProcessing()
    {
        return (bool) get_transient($this->key() . '_lock');
    }

    public function markProcessing()
    {
        set_transient($this->key() . '_lock', time(), 90);
    }

    public function clearProcessing()
    {
        delete_transient($this->key() . '_lock');
    }
}