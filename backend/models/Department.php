<?php
class Department {
    private $conn;
    private $table_name = "departments";

    public $id;
    public $name;
    public $code;
    public $directorate_id;

    public function __construct($db) {
        $this->conn = $db;
    }

    function read() {
        $query = "SELECT d.*, dr.name as directorate_name, dv.name as division_name
                  FROM " . $this->table_name . " d
                  LEFT JOIN directorates dr ON d.directorate_id = dr.id
                  LEFT JOIN divisions dv ON dr.division_id = dv.id
                  ORDER BY d.name";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt;
    }

    function create() {
        $query = "INSERT INTO " . $this->table_name . "
                  SET name=:name, code=:code, directorate_id=:directorate_id";
                  
        $stmt = $this->conn->prepare($query);
        
        $this->name = htmlspecialchars(strip_tags($this->name));
        $this->code = htmlspecialchars(strip_tags($this->code));
        $this->directorate_id = htmlspecialchars(strip_tags($this->directorate_id));
        
        $stmt->bindParam(":name", $this->name);
        $stmt->bindParam(":code", $this->code);
        $stmt->bindParam(":directorate_id", $this->directorate_id);
        
        if($stmt->execute()) {
            return true;
        }
        return false;
    }
    
    function delete() {
        $query = "DELETE FROM " . $this->table_name . " WHERE id = ?";
        $stmt = $this->conn->prepare($query);
        $this->id = htmlspecialchars(strip_tags($this->id));
        $stmt->bindParam(1, $this->id);
        
        if($stmt->execute()) {
            return true;
        }
        return false;
    }
}
?>