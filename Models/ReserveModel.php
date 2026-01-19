<?php

class Reserve {
    public function __construct() {
        $this->db = new Connection();
    }

    public function get_reserve_list() {
        $s_query = "SELECT * FROM reserve";
        $o_result = $this->db->query($s_query);
        return $o_result;
    }
}

?>