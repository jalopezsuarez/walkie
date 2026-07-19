package rocks.howto.walkie.feature.pairing

import android.graphics.Bitmap
import android.graphics.Color
import com.google.zxing.BarcodeFormat
import com.google.zxing.EncodeHintType
import com.google.zxing.qrcode.QRCodeWriter

/** Render [content] as a black/white QR bitmap using ZXing (offline, no deps). */
fun qrBitmap(content: String, size: Int = 720): Bitmap {
    val hints = mapOf(EncodeHintType.MARGIN to 1)
    val matrix = QRCodeWriter().encode(content, BarcodeFormat.QR_CODE, size, size, hints)
    val pixels = IntArray(size * size)
    for (y in 0 until size) {
        val offset = y * size
        for (x in 0 until size) {
            pixels[offset + x] = if (matrix[x, y]) Color.BLACK else Color.WHITE
        }
    }
    return Bitmap.createBitmap(size, size, Bitmap.Config.ARGB_8888).apply {
        setPixels(pixels, 0, size, 0, 0, size, size)
    }
}

/** Extract the pairing token from a scanned value ("…/web/#p=TOKEN" or bare). */
fun extractPairToken(raw: String): String? {
    val afterP = raw.substringAfter("p=", "")
    val token = if (afterP.isNotBlank()) afterP else raw.trim()
    return token.takeIf { it.isNotBlank() && !it.contains('/') }
}
