<?php
class Directorate {
    private $conn;
    private $table_name = "directorates";

    public $id;
    public $name;
    public $code;
    public $division_id;

    public function __construct($db) {
        $this->conn = $db;
    }

    function read() {
        $query = "SELECT d.*, dv.name as division_name 
                  FROM " . $this->table_name . " d
                  LEFT JOIN divisions dv ON d.division_id = dv.id
                  ORDER BY d.name";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt;
    }

    function create() {
        $query = "INSERT INTO " . $this->table_name . "
                  SET name=:name, code=:code, division_id=:division_id";
                  
        $stmt = $this->conn->prepare($query);
        
        $this->name = htmlspecialchars(strip_tags($this->name));
        $this->code = htmlspecialchars(strip_tags($this->code));
        $this->division_id = htmlspecialchars(strip_tags($this->division_id));
        
        $stmt->bindParam(":name", $this->name);
        $stmt->bindParam(":code", $this->code);
        $stmt->bindParam(":division_id", $this->division_id);
        
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