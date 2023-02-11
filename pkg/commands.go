package barertc

import "strings"

// ProcessCommand parses a chat message for "/commands"
func (s *Server) ProcessCommand(sub *Subscriber, msg Message) bool {
	if len(msg.Message) == 0 || msg.Message[0] != '/' {
		return false
	}

	// Line begins with a slash, parse it apart.
	words := strings.Fields(msg.Message)
	if len(words) == 0 {
		return false
	}

	// Moderator commands.
	if sub.JWTClaims != nil && sub.JWTClaims.IsAdmin {
		switch words[0] {
		case "/kick":
			if len(words) == 1 {
				sub.ChatServer("Usage: `/kick username` to remove the user from the chat room.")
			}
			username := words[1]
			other, err := s.GetSubscriber(username)
			if err != nil {
				sub.ChatServer("/kick: username not found: %s", username)
			} else {
				other.ChatServer("You have been kicked from the chat room by %s", sub.Username)
				other.SendJSON(Message{
					Action: ActionKick,
				})
				s.DeleteSubscriber(other)
				sub.ChatServer("%s has been kicked from the room", username)
			}
			return true
		case "/nsfw":
			if len(words) == 1 {
				sub.ChatServer("Usage: `/nsfw username` to add the NSFW flag to their camera.")
			}
			username := words[1]
			other, err := s.GetSubscriber(username)
			if err != nil {
				sub.ChatServer("/nsfw: username not found: %s", username)
			} else {
				other.ChatServer("Your camera has been marked as NSFW by %s", sub.Username)
				other.VideoNSFW = true
				other.SendMe()
				s.SendWhoList()
				sub.ChatServer("%s has their camera marked as NSFW", username)
			}
			return true
		case "/help":
			sub.ChatServer(RenderMarkdown("Moderator commands are:\n\n" +
				"* `/kick <username>` to kick from chat\n" +
				"* `/nsfw <username>` to mark their camera NSFW\n" +
				"* `/help` to show this message",
			))
			return true
		}
	}

	// Not handled.
	return false
}
