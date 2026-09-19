<?php
class User {
    private $conn;
    private $table_name = "users";

    public $id;
    public $full_name;
    public $email;
    public $password;
    public $role;
    public $division_id;
    public $directorate_id;
    public $department_id;
    public $supervisor_id;
    public $is_active;
    public $created_at;

    public function __construct($db) {
        $this->conn = $db;
    }

    function emailExists() {
        $query = "SELECT id, full_name, email, password, role
                  FROM " . $this->table_name . "
                  WHERE email = ?
                  LIMIT 0,1";

        $stmt = $this->conn->prepare($query);
        $this->email=htmlspecialchars(strip_tags($this->email));
        $stmt->bindParam(1, $this->email);
        $stmt->execute();
        $num = $stmt->rowCount();

        if($num>0) {
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $this->id = $row['id'];
            $this->full_name = $row['full_name'];
            $this->email = $row['email'];
            $this->password = $row['password'];
            $this->role = $row['role'];
            return true;
        }
        return false;
    }

    function create() {
        $query = "INSERT INTO " . $this->table_name . "
                  SET full_name=:full_name, email=:email, password=:password, 
                  role=:role, division_id=:division_id, directorate_id=:directorate_id,
                  department_id=:department_id, supervisor_id=:supervisor_id";

        $stmt = $this->conn->prepare($query);

        // Sanitize
        $this->full_name=htmlspecialchars(strip_tags($this->full_name));
        $this->email=htmlspecialchars(strip_tags($this->email));
        $this->password=htmlspecialchars(strip_tags($this->password));
        $this->role=htmlspecialchars(strip_tags($this->role));
        $this->division_id=htmlspecialchars(strip_tags($this->division_id));
        $this->directorate_id=htmlspecialchars(strip_tags($this->directorate_id));
        $this->department_id=htmlspecialchars(strip_tags($this->department_id));
        $this->supervisor_id=htmlspecialchars(strip_tags($this->supervisor_id));

        // Bind values
        $stmt->bindParam(":full_name", $this->full_name);
        $stmt->bindParam(":email", $this->email);
        $stmt->bindParam(":role", $this->role);
        $stmt->bindParam(":division_id", $this->division_id);
        $stmt->bindParam(":directorate_id", $this->directorate_id);
        $stmt->bindParam(":department_id", $this->department_id);
        $stmt->bindParam(":supervisor_id", $this->supervisor_id);

        // Hash password
        $password_hash = password_hash($this->password, PASSWORD_BCRYPT);
        $stmt->bindParam(":password", $password_hash);

        if($stmt->execute()) {
            return true;
        }
        return false;
    }
}
?>