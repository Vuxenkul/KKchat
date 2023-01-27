package barertc

import (
	"fmt"
	"time"

	"git.kirsle.net/apps/barertc/pkg/log"
)

// OnLogin handles "login" actions from the client.
func (s *Server) OnLogin(sub *Subscriber, msg Message) {
	// Ensure the username is unique, or rename it.
	var duplicate bool
	for _, other := range s.IterSubscribers() {
		if other.ID != sub.ID && other.Username == msg.Username {
			duplicate = true
			break
		}
	}

	if duplicate {
		// Give them one that is unique.
		msg.Username = fmt.Sprintf("%s %d",
			msg.Username,
			time.Now().Nanosecond(),
		)
	}

	// Use their username.
	sub.Username = msg.Username
	log.Debug("OnLogin: %s joins the room", sub.Username)

	// Tell everyone they joined.
	s.Broadcast(Message{
		Action:   ActionPresence,
		Username: msg.Username,
		Message:  "has joined the room!",
	})

	// Send the user back their settings.
	sub.SendMe()

	// Send the WhoList to everybody.
	s.SendWhoList()
}

// OnMessage handles a chat message posted by the user.
func (s *Server) OnMessage(sub *Subscriber, msg Message) {
	log.Info("[%s] %s", sub.Username, msg.Message)
	if sub.Username == "" {
		sub.ChatServer("You must log in first.")
		return
	}

	// Broadcast a chat message to the room.
	s.Broadcast(Message{
		Action:   ActionMessage,
		Username: sub.Username,
		Message:  msg.Message,
	})
}

// OnMe handles current user state updates.
func (s *Server) OnMe(sub *Subscriber, msg Message) {
	if msg.VideoActive {
		log.Debug("User %s turns on their video feed", sub.Username)
	}

	sub.VideoActive = msg.VideoActive

	// Sync the WhoList to everybody.
	s.SendWhoList()
}

// OnOpen is a client wanting to start WebRTC with another, e.g. to see their camera.
func (s *Server) OnOpen(sub *Subscriber, msg Message) {
	// Look up the other subscriber.
	other, err := s.GetSubscriber(msg.Username)
	if err != nil {
		sub.ChatServer(err.Error())
		return
	}

	// Make up a WebRTC shared secret and send it to both of them.
	secret := RandomString(16)
	log.Info("WebRTC: %s opens %s with secret %s", sub.Username, other.Username, secret)

	// Ring the target of this request and give them the secret.
	other.SendJSON(Message{
		Action:     ActionRing,
		Username:   sub.Username,
		OpenSecret: secret,
	})

	// To the caller, echo back the Open along with the secret.
	sub.SendJSON(Message{
		Action:     ActionOpen,
		Username:   other.Username,
		OpenSecret: secret,
	})
}

// OnCandidate handles WebRTC candidate signaling.
func (s *Server) OnCandidate(sub *Subscriber, msg Message) {
	// Look up the other subscriber.
	other, err := s.GetSubscriber(msg.Username)
	if err != nil {
		sub.ChatServer(err.Error())
		return
	}

	other.SendJSON(Message{
		Action:    ActionCandidate,
		Username:  sub.Username,
		Candidate: msg.Candidate,
	})
}

// OnSDP handles WebRTC sdp signaling.
func (s *Server) OnSDP(sub *Subscriber, msg Message) {
	// Look up the other subscriber.
	other, err := s.GetSubscriber(msg.Username)
	if err != nil {
		sub.ChatServer(err.Error())
		return
	}

	other.SendJSON(Message{
		Action:      ActionSDP,
		Username:    sub.Username,
		Description: msg.Description,
	})
}
