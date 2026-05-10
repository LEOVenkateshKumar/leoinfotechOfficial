<?php
/**
 * UPDATED: /api/chatbot-handler.php
 * Now LOADS MODELS DYNAMICALLY from Ollama
 */

define('AKKUAPPS_CORE', true);
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: ' . SITE_URL);

// Check user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// CHANGE THIS LINE (around line 20)
define('OLLAMA_HOST', 'http://localhost:11434');
define('OLLAMA_TIMEOUT', 60);
define('OLLAMA_TEMPERATURE', 0.7);
define('OLLAMA_MAX_TOKENS', 512);

class OllamaChatBot {
    private $userId;
    private $db;
    
    public function __construct($userId) {
        $this->userId = $userId;
        $this->db = getDB();
    }
    
    /**
 * GET MODELS FROM OLLAMA
 */
public function getModels() {
    try {
        $url = OLLAMA_HOST . '/api/tags';
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FAILONERROR => true
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) {
            error_log('Curl error: ' . $curlError);
            return [];
        }
        
        if ($httpCode !== 200) {
            error_log('HTTP error: ' . $httpCode);
            return [];
        }
        
        $data = json_decode($response, true);
        $models = [];
        
        // The response from your Ollama has 'models' array with 'name' field
        if (isset($data['models']) && is_array($data['models'])) {
            foreach ($data['models'] as $model) {
                // Extract just the base name for display
                $name = $model['name'];
                $display = str_replace(':cloud', '', $name);
                $display = str_replace('-', ' ', $display);
                $display = ucwords($display) . ' (Cloud)';
                
                $models[] = [
                    'name' => $name,
                    'display' => $display
                ];
            }
        }
        
        return $models;
        
    } catch (Exception $e) {
        error_log('Get models error: ' . $e->getMessage());
        return [];
    }
}
    
    /**
     * FORMAT MODEL NAME FOR DISPLAY
     */
    private function formatModelName($name) {
        // Convert "glm-5:cloud" to "GLM 5 (Cloud)"
        $name = str_replace('-', ' ', $name);
        $name = str_replace(':cloud', ' (Cloud)', $name);
        $name = ucwords($name);
        return $name;
    }
    
    /**
     * SEND MESSAGE
     */
    public function sendMessage($prompt, $modelName) {
        try {
            if (empty(trim($prompt))) {
                throw new Exception('Prompt cannot be empty');
            }
            
            if (strlen($prompt) > 2000) {
                throw new Exception('Message too long');
            }
            
            if (!$this->checkRateLimit()) {
                throw new Exception('Too many requests. Wait a moment.');
            }
            
            // Call Ollama
            $response = $this->callOllama($prompt, $modelName);
            
            // Save to database
            $this->saveConversation($prompt, $response, $modelName);
            
            return [
                'success' => true,
                'response' => $response,
                'model' => $modelName,
                'timestamp' => date('Y-m-d H:i:s')
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * CALL OLLAMA API
     */
    private function callOllama($prompt, $modelName) {
        $url = OLLAMA_HOST . '/api/generate';
        
        $data = [
            'model' => $modelName,
            'prompt' => $prompt,
            'stream' => false,
            'temperature' => OLLAMA_TEMPERATURE,
        ];
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => 1,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => OLLAMA_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) {
            throw new Exception('Ollama connection error: ' . $curlError);
        }
        
        if ($httpCode !== 200) {
            throw new Exception('Ollama error: HTTP ' . $httpCode);
        }
        
        $decoded = json_decode($response, true);
        return $decoded['response'] ?? 'No response';
    }
    
    /**
     * SAVE CONVERSATION
     */
    private function saveConversation($userMessage, $aiResponse, $modelName) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO chatbot_conversations 
                (user_id, model_name, user_message, ai_response, created_at) 
                VALUES (?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                $this->userId,
                $modelName,
                $userMessage,
                substr($aiResponse, 0, 5000)
            ]);
            
        } catch (Exception $e) {
            error_log('Save error: ' . $e->getMessage());
        }
    }
    
    /**
     * RATE LIMITING
     */
    private function checkRateLimit() {
        $key = 'chatbot_last_' . $this->userId;
        
        if (isset($_SESSION[$key])) {
            if (time() - $_SESSION[$key] < 3) {
                return false;
            }
        }
        
        $_SESSION[$key] = time();
        return true;
    }
    
    /**
     * GET HISTORY
     */
    public function getHistory($limit = 20) {
        try {
            $stmt = $this->db->prepare("
                SELECT * FROM chatbot_conversations 
                WHERE user_id = ? 
                ORDER BY created_at DESC 
                LIMIT ?
            ");
            $stmt->execute([$this->userId, $limit]);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            return [];
        }
    }
    
    /**
     * CLEAR CONVERSATION
     */
    public function clearConversation() {
        try {
            $stmt = $this->db->prepare("
                DELETE FROM chatbot_conversations 
                WHERE user_id = ?
            ");
            $stmt->execute([$this->userId]);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
}

// HANDLE REQUESTS
try {
    $action = $_REQUEST['action'] ?? '';
    $chatBot = new OllamaChatBot($_SESSION['user_id']);
    
    switch ($action) {
        case 'send_message':
            $prompt = $_POST['prompt'] ?? '';
            $model = $_POST['model'] ?? 'glm-5:cloud';
            $result = $chatBot->sendMessage($prompt, $model);
            echo json_encode($result);
            break;
            
        case 'get_models':
            // THIS IS THE NEW PART - LOADS YOUR OLLAMA MODELS
            $models = $chatBot->getModels();
            echo json_encode([
                'success' => true,
                'models' => $models
            ]);
            break;
            
        case 'get_history':
            $history = $chatBot->getHistory();
            echo json_encode([
                'success' => true,
                'history' => $history
            ]);
            break;
            
        case 'clear':
            $result = $chatBot->clearConversation();
            echo json_encode([
                'success' => $result,
                'message' => $result ? 'Cleared' : 'Failed'
            ]);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

?>