#!/usr/bin/env bash
# MonoForge game gateway: a cheap VPS (such as Oracle Cloud's free tier) receives the players'
# game traffic on a port range and forwards it through WireGuard to a node at home. Players join
# with the VPS address (e.g. play.monoforge.net:25577); the node's home IP stays hidden.
#
# Only the game ports are forwarded. SSH and everything else on the VPS stay as they are, and the
# node keeps its normal internet connection: only replies to gateway traffic go back through it.
#
# Run with sudo, in this order (only public keys are ever copied between the machines):
#
#   1. On the VPS:   sudo bash gateway.sh vps-init [--ports 25565-25700]
#        Installs WireGuard and prints the VPS's public key and IP.
#   2. On the node:  sudo bash gateway.sh node-init --gateway <VPS IP> --gateway-key <VPS public key>
#        Installs WireGuard, connects to the VPS and prints the node's public key.
#   3. On the VPS:   sudo bash gateway.sh vps-add-node --node-key <node public key>
#        Starts forwarding the ports to the node.
#
#   sudo bash gateway.sh status       the WireGuard link and the forwarded ports (either machine)
#   sudo bash gateway.sh remove       stops and removes the gateway on this machine
#
# Options:
#   --ports LIST    port ranges to forward, TCP and UDP, e.g. 25565-25700,7777-7800 (VPS only;
#                   default 25565-25700). SSH (22) and the WireGuard port can never be forwarded.
#   --snat          the game servers see every player as the gateway (10.66.0.1) instead of their
#                   real IP. Only use it if the default does not work (VPS only).
#
# Private keys are created on each machine, stay in /etc/wireguard (mode 600) and are never printed.
set -euo pipefail

WG=wg-monoforge
CONF=/etc/wireguard/$WG.conf
WG_PORT=51820
VPS_ADDR=10.66.0.1
NODE_ADDR=10.66.0.2
MARK=0x4d46
TABLE=4646
STATE=/etc/wireguard/$WG.ports

die() { echo "Error: $*" >&2; exit 1; }

[ "$(id -u)" -eq 0 ] || die "run this with sudo."

command="${1:-}"
[ $# -gt 0 ] && shift

ports="25565-25700"
snat=0
gateway=""
gateway_key=""
node_key=""
while [ $# -gt 0 ]; do
    case "$1" in
        --ports) ports="${2:-}"; shift 2 ;;
        --snat) snat=1; shift ;;
        --gateway) gateway="${2:-}"; shift 2 ;;
        --gateway-key) gateway_key="${2:-}"; shift 2 ;;
        --node-key) node_key="${2:-}"; shift 2 ;;
        *) die "unknown option $1" ;;
    esac
done

valid_key() { [[ "$1" =~ ^[A-Za-z0-9+/]{42}[AEIMQUYcgkosw048]=$ ]]; }

# "25565-25700,7777" -> "25565:25700,7777" for iptables multiport, refusing SSH and WireGuard.
multiport() {
    local out="" part from to
    IFS=',' read -ra parts <<< "$1"
    [ "${#parts[@]}" -ge 1 ] && [ "${#parts[@]}" -le 7 ] || die "--ports takes 1 to 7 ranges."
    for part in "${parts[@]}"; do
        if [[ "$part" =~ ^([0-9]{1,5})-([0-9]{1,5})$ ]]; then
            from=${BASH_REMATCH[1]}; to=${BASH_REMATCH[2]}
        elif [[ "$part" =~ ^([0-9]{1,5})$ ]]; then
            from=${BASH_REMATCH[1]}; to=$from
        else
            die "invalid port range: $part"
        fi
        [ "$from" -ge 1024 ] && [ "$to" -le 65535 ] && [ "$from" -le "$to" ] || die "ports must be between 1024 and 65535: $part"
        [ "$WG_PORT" -lt "$from" ] || [ "$WG_PORT" -gt "$to" ] || die "the range $part includes the WireGuard port $WG_PORT."
        out+="${out:+,}$from:$to"
    done
    echo "$out"
}

install_wireguard() {
    if ! command -v wg >/dev/null || ! command -v wg-quick >/dev/null; then
        echo "Installing WireGuard ..."
        apt-get update -qq && DEBIAN_FRONTEND=noninteractive apt-get install -y -qq wireguard-tools iptables >/dev/null
    fi
}

# Creates this machine's key pair once; prints the public key.
ensure_keys() {
    umask 077
    mkdir -p /etc/wireguard
    [ -s "/etc/wireguard/$WG.key" ] || wg genkey > "/etc/wireguard/$WG.key"
    chmod 600 "/etc/wireguard/$WG.key"
    wg pubkey < "/etc/wireguard/$WG.key"
}

private_key() { cat "/etc/wireguard/$WG.key"; }

public_iface() { ip -4 route show default | awk '{for (i = 1; i < NF; i++) if ($i == "dev") { print $(i + 1); exit }}'; }

restart() {
    systemctl enable "wg-quick@$WG" >/dev/null 2>&1
    systemctl restart "wg-quick@$WG"
}

vps_conf() {
    local dports="$1" peer="$2" iface masq=""
    iface=$(public_iface)
    [ -n "$iface" ] || die "could not find the public network interface."
    [ "$snat" -eq 1 ] && masq="iptables -t nat -A POSTROUTING -o $WG -j MASQUERADE"

    # Oracle's Ubuntu images REJECT in INPUT and FORWARD by default, so the rules are inserted
    # first. They are added when the link comes up and removed when it goes down.
    cat > "$CONF" <<EOF
# MonoForge game gateway (VPS side). Forwarded ports: $dports (TCP and UDP) on $iface.
[Interface]
Address = $VPS_ADDR/24
ListenPort = $WG_PORT
PrivateKey = $(private_key)
PostUp = sysctl -qw net.ipv4.ip_forward=1
PostUp = iptables -I INPUT 1 -p udp --dport $WG_PORT -j ACCEPT
PostUp = iptables -I FORWARD 1 -i $WG -j ACCEPT
PostUp = iptables -I FORWARD 1 -i $iface -o $WG -p tcp -m multiport --dports $dports -j ACCEPT
PostUp = iptables -I FORWARD 1 -i $iface -o $WG -p udp -m multiport --dports $dports -j ACCEPT
PostUp = iptables -t nat -A PREROUTING -i $iface -p tcp -m multiport --dports $dports -j DNAT --to-destination $NODE_ADDR
PostUp = iptables -t nat -A PREROUTING -i $iface -p udp -m multiport --dports $dports -j DNAT --to-destination $NODE_ADDR
${masq:+PostUp = $masq}
PostDown = iptables -D INPUT -p udp --dport $WG_PORT -j ACCEPT || true
PostDown = iptables -D FORWARD -i $WG -j ACCEPT || true
PostDown = iptables -D FORWARD -i $iface -o $WG -p tcp -m multiport --dports $dports -j ACCEPT || true
PostDown = iptables -D FORWARD -i $iface -o $WG -p udp -m multiport --dports $dports -j ACCEPT || true
PostDown = iptables -t nat -D PREROUTING -i $iface -p tcp -m multiport --dports $dports -j DNAT --to-destination $NODE_ADDR || true
PostDown = iptables -t nat -D PREROUTING -i $iface -p udp -m multiport --dports $dports -j DNAT --to-destination $NODE_ADDR || true
${masq:+PostDown = iptables -t nat -D POSTROUTING -o $WG -j MASQUERADE || true}
EOF
    if [ -n "$peer" ]; then
        cat >> "$CONF" <<EOF

[Peer]
# The MonoForge node.
PublicKey = $peer
AllowedIPs = $NODE_ADDR/32
EOF
    fi
    chmod 600 "$CONF"
}

case "$command" in
    vps-init)
        dports=$(multiport "$ports")
        install_wireguard
        pub=$(ensure_keys)
        echo "$dports $snat" > "$STATE"
        vps_conf "$dports" ""
        restart
        ip=$(curl -fsS4 --max-time 10 https://api.ipify.org 2>/dev/null || echo "<this VPS's public IP>")
        echo
        echo "The gateway is installed. Forwarded ports (TCP and UDP): ${dports//:/-}"
        echo "Next, on the node run:"
        echo
        echo "  sudo bash gateway.sh node-init --gateway $ip --gateway-key $pub"
        echo
        echo "Also allow UDP $WG_PORT and these ports (TCP and UDP) in the VPS provider's firewall"
        echo "(Oracle: the subnet's Security List > Add Ingress Rules)."
        ;;

    vps-add-node)
        valid_key "$node_key" || die "--node-key must be the public key node-init printed."
        [ -s "$STATE" ] || die "run vps-init first."
        read -r dports snat < "$STATE"
        vps_conf "$dports" "$node_key"
        restart
        echo "Forwarding ${dports//:/-} (TCP and UDP) to the node. Check the link with: sudo bash gateway.sh status"
        ;;

    node-init)
        [ -n "$gateway" ] || die "--gateway <VPS IP> is required."
        [[ "$gateway" =~ ^[0-9]{1,3}(\.[0-9]{1,3}){3}$ ]] || die "--gateway must be the VPS's IPv4 address."
        valid_key "$gateway_key" || die "--gateway-key must be the public key vps-init printed."
        install_wireguard
        pub=$(ensure_keys)

        # The node keeps its normal default route (Table = off). Connections that arrive through the
        # gateway are marked, and only their replies are routed back through it, so the game
        # servers see the players' real IPs. rp_filter is loosened for that return path.
        cat > "$CONF" <<EOF
# MonoForge game gateway (node side).
[Interface]
Address = $NODE_ADDR/24
PrivateKey = $(private_key)
Table = off
PostUp = sysctl -qw net.ipv4.conf.all.src_valid_mark=1 net.ipv4.conf.all.rp_filter=2 net.ipv4.conf.%i.rp_filter=2
PostUp = ip route replace default dev %i table $TABLE
PostUp = ip rule add fwmark $MARK table $TABLE priority $TABLE
PostUp = iptables -t mangle -A PREROUTING -i %i -m conntrack --ctstate NEW -j CONNMARK --set-mark $MARK
PostUp = iptables -t mangle -A PREROUTING ! -i %i -m connmark --mark $MARK -j MARK --set-mark $MARK
PostUp = iptables -t mangle -A OUTPUT -m connmark --mark $MARK -j MARK --set-mark $MARK
PostDown = ip rule del fwmark $MARK table $TABLE priority $TABLE || true
PostDown = ip route flush table $TABLE || true
PostDown = iptables -t mangle -D PREROUTING -i %i -m conntrack --ctstate NEW -j CONNMARK --set-mark $MARK || true
PostDown = iptables -t mangle -D PREROUTING ! -i %i -m connmark --mark $MARK -j MARK --set-mark $MARK || true
PostDown = iptables -t mangle -D OUTPUT -m connmark --mark $MARK -j MARK --set-mark $MARK || true

[Peer]
# The MonoForge gateway VPS.
PublicKey = $gateway_key
Endpoint = $gateway:$WG_PORT
AllowedIPs = 0.0.0.0/0
PersistentKeepalive = 25
EOF
        chmod 600 "$CONF"
        restart
        echo
        echo "The node is connected to the gateway. Finish on the VPS with:"
        echo
        echo "  sudo bash gateway.sh vps-add-node --node-key $pub"
        ;;

    status)
        [ -f "$CONF" ] || die "no gateway is set up on this machine."
        systemctl is-active --quiet "wg-quick@$WG" && echo "WireGuard: running" || echo "WireGuard: NOT running (journalctl -u wg-quick@$WG -n 30)"
        wg show "$WG" 2>/dev/null | grep -E "peer|endpoint|latest handshake|transfer" | sed 's/^/  /' || true
        if [ -s "$STATE" ]; then
            read -r dports _ < "$STATE"
            echo "Forwarded ports (TCP and UDP): ${dports//:/-}"
        fi
        if [ "$(grep -c '^Table = off' "$CONF")" -gt 0 ]; then
            ping -c 2 -W 2 "$VPS_ADDR" >/dev/null 2>&1 && echo "The gateway answers at $VPS_ADDR." || echo "The gateway does not answer at $VPS_ADDR yet."
        else
            ping -c 2 -W 2 "$NODE_ADDR" >/dev/null 2>&1 && echo "The node answers at $NODE_ADDR." || echo "The node does not answer at $NODE_ADDR yet."
        fi
        ;;

    remove)
        systemctl disable --now "wg-quick@$WG" >/dev/null 2>&1 || true
        rm -f "$CONF" "$STATE"
        echo "The gateway is removed from this machine (the key in /etc/wireguard/$WG.key is kept)."
        ;;

    *)
        sed -n '2,30p' "$0" | sed 's/^# \{0,1\}//'
        exit 1
        ;;
esac
