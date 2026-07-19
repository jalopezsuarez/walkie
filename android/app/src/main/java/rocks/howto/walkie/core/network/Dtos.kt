package rocks.howto.walkie.core.network

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

/* Wire models — field names mirror the existing PHP API 1:1. */

@Serializable
data class User(
    val id: Long,
    val email: String? = null,
    @SerialName("display_name") val displayName: String = "",
)

@Serializable
data class AuthResponse(val token: String, val user: User)

@Serializable
data class MeResponse(val user: User)

@Serializable
data class TokenResponse(
    @SerialName("access_token") val accessToken: String,
    @SerialName("refresh_token") val refreshToken: String? = null,
    @SerialName("expires_in") val expiresIn: Long = 0,
)

@Serializable
data class QrResponse(
    val token: String,
    @SerialName("pair_url") val pairUrl: String,
)

@Serializable
data class LinkInfo(
    @SerialName("link_id") val linkId: Long,
    @SerialName("user_id") val userId: Long,
    @SerialName("display_name") val displayName: String,
)

@Serializable
data class ClaimResponse(val ok: Boolean = true, val link: LinkInfo)

@Serializable
data class Contact(
    @SerialName("link_id") val linkId: Long,
    @SerialName("user_id") val userId: Long,
    @SerialName("display_name") val displayName: String,
    val unread: Int = 0,
)

@Serializable
data class LinksResponse(val links: List<Contact> = emptyList())

@Serializable
data class ContactRef(
    @SerialName("user_id") val userId: Long,
    @SerialName("display_name") val displayName: String,
)

@Serializable
data class Message(
    val id: Long,
    val mine: Boolean = false,
    val type: String,                                  // "text" | "audio"
    val delivered: Boolean = false,
    val read: Boolean = false,
    @SerialName("created_at") val createdAt: String,
    @SerialName("expires_at") val expiresAt: String? = null,
    val text: String? = null,
    val audio: String? = null,                          // base64 payload
    val mime: String? = null,
    @SerialName("duration_ms") val durationMs: Long? = null,
)

@Serializable
data class MessagesResponse(val contact: ContactRef, val messages: List<Message> = emptyList())

@Serializable
data class SendResult(val id: Long, val type: String)

@Serializable
data class Status(val id: Long, val delivered: Boolean = false, val read: Boolean = false)

@Serializable
data class StatusesResponse(val statuses: List<Status> = emptyList())

/* Request bodies */
@Serializable data class EmailBody(val email: String)
@Serializable data class VerifyBody(val email: String, val code: String)
@Serializable data class ClaimBody(val token: String)
@Serializable data class TextBody(val type: String = "text", val text: String)
@Serializable data class AudioBody(
    val type: String = "audio",
    val audio: String,
    val mime: String,
    @SerialName("duration_ms") val durationMs: Long? = null,
)
@Serializable data class ReadBody(val ids: List<Long>)
@Serializable data class ProfilePatch(@SerialName("display_name") val displayName: String)
@Serializable data class DeviceBody(val token: String, val platform: String = "android")
