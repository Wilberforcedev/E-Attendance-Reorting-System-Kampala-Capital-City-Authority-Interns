<?php
class Report {
    private $conn;
    private $table_name = "reports";

    public $id;
    public $intern_id;
    public $week_number;
    public $start_date;
    public $end_date;
    public $activities;
    public $challenges;
    public $tasks_in_progress;
    public $file_path;
    public $status;
    public $supervisor_comments;

    public function __construct($db) {
        $this->conn = $db;
    }

    function create() {
        $query = "INSERT INTO " . $this->table_name . "
                  SET intern_id=:intern_id, week_number=:week_number,
                  start_date=:start_date, end_date=:end_date,
                  activities=:activities, challenges=:challenges,
                  tasks_in_progress=:tasks_in_progress, file_path=:file_path,
                  status=:status";
                  
        $stmt = $this->conn->prepare($query);
        
        $this->intern_id = htmlspecialchars(strip_tags($this->intern_id));
        $this->week_number = htmlspecialchars(strip_tags($this->week_number));
        $this->start_date = htmlspecialchars(strip_tags($this->start_date));
        $this->end_date = htmlspecialchars(strip_tags($this->end_date));
        $this->activities = htmlspecialchars(strip_tags($this->activities));
        $this->challenges = htmlspecialchars(strip_tags($this->challenges));
        $this->tasks_in_progress = htmlspecialchars(strip_tags($this->tasks_in_progress));
        $this->file_path = htmlspecialchars(strip_tags($this->file_path));
        $this->status = htmlspecialchars(strip_tags($this->status));
        
        $stmt->bindParam(":intern_id", $this->intern_id);
        $stmt->bindParam(":week_number", $this->week_number);
        $stmt->bindParam(":start_date", $this->start_date);
        $stmt->bindParam(":end_date", $this->end_date);
        $stmt->bindParam(":activities", $this->activities);
        $stmt->bindParam(":challenges", $this->challenges);
        $stmt->bindParam(":tasks_in_progress", $this->tasks_in_progress);
        $stmt->bindParam(":file_path", $this->file_path);
        $stmt->bindParam(":status", $this->status);
        
        if($stmt->execute()) {
            return true;
        }
        return false;
    }

    function updateStatus() {
        $query = "UPDATE " . $this->table_name . "
                  SET status=:status, supervisor_comments=:supervisor_comments
                  WHERE id=:id";
                  
        $stmt = $this->conn->prepare($query);
        
        $this->status = htmlspecialchars(strip_tags($this->status));
        $this->supervisor_comments = htmlspecialchars(strip_tags($this->supervisor_comments));
        $this->id = htmlspecialchars(strip_tags($this->id));
        
        $stmt->bindParam(":status", $this->status);
        $stmt->bindParam(":supervisor_comments", $this->supervisor_comments);
        $stmt->bindParam(":id", $this->id);
        
        if($stmt->execute()) {
            return true;
        }
        return false;
    }

    function readByIntern($intern_id) {
        $query = "SELECT * FROM " . $this->table_name . " 
                  WHERE intern_id = ?
                  ORDER BY week_number DESC";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(1, $intern_id);
        $stmt->execute();
        return $stmt;
    }

    function readPendingApprovals($supervisor_id) {
        $query = "SELECT r.*, u.full_name as intern_name
                  FROM " . $this->table_name . " r
                  JOIN users u ON r.intern_id = u.id
                  WHERE u.supervisor_id = ? AND r.status = 'submitted'
                  ORDER BY r.created_at DESC";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(1, $supervisor_id);
        $stmt->execute();
        return $stmt;
    }
}
?>