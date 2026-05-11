output "server_ip" {
  description = "Public IP of the SatView server"
  value       = hcloud_server.satview.ipv4_address
}

output "server_status" {
  description = "Server status"
  value       = hcloud_server.satview.status
}
