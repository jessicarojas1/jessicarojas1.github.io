package main
import "crypto/rand"
func token(b []byte) { rand.Read(b) }
