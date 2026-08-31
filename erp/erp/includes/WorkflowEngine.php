<?php

class WorkflowEngine {
    protected $pdo;
    protected $transitions = [];
    protected $hooks = [];

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Define allowed transitions
     * $map = ['draft' => ['sent', 'canceled'], 'sent' => ['accepted', 'rejected']]
     */
    public function setTransitions(array $map) {
        $this->transitions = $map;
    }

    /**
     * Register a hook for a state entry
     */
    public function onEnter($status, callable $callback) {
        $this->hooks[$status][] = $callback;
    }

    /**
     * Attempt to transition an entity to a new status
     */
    public function transition($entityTable, $entityId, $newStatus, $currentStatus = null) {
        if ($currentStatus === null) {
            $stmt = $this->pdo->prepare("SELECT status FROM $entityTable WHERE id = ?");
            $stmt->execute([$entityId]);
            $currentStatus = $stmt->fetchColumn();
        }

        if (!$this->canTransition($currentStatus, $newStatus)) {
            throw new Exception("Invalid workflow transition from '$currentStatus' to '$newStatus'");
        }

        // Execute Transaction
        try {
            $this->pdo->beginTransaction();

            // 1. Update Status
            $stmt = $this->pdo->prepare("UPDATE $entityTable SET status = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$newStatus, $entityId]);

            // 2. Log History (if table exists - generic idea)
            // ...

            // 3. Trigger Hooks
            if (isset($this->hooks[$newStatus])) {
                foreach ($this->hooks[$newStatus] as $hook) {
                    call_user_func($hook, $entityId, $this->pdo);
                }
            }

            $this->pdo->commit();
            return true;

        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function canTransition($from, $to) {
        // Allow self-loop (updating same status)? Maybe not.
        // Super admin override?
        
        if (!isset($this->transitions[$from])) return false;
        return in_array($to, $this->transitions[$from]);
    }
}
