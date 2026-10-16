// functions/index.js
// Firebase Cloud Functions v2 — VietQR Webhook + Atomic Balance
// Deploy: firebase deploy --only functions

const functions = require("firebase-functions/v2");
const admin = require("firebase-admin");
const crypto = require("crypto");

admin.initializeApp();
const db = admin.firestore();

// ================================================================
// CONFIG — thay bằng thông tin thật
// ================================================================
const CONFIG = {
    // VietQR / Ngân hàng
    BANK_BIN: "0353774085",
    ACCOUNT_NUMBER: "0353774085",
    ACCOUNT_NAME: "BUI VAN DUNG",

    // Webhook secret — tạo random: crypto.randomBytes(32).toString('hex')
    WEBHOOK_SECRET: process.env.WEBHOOK_SECRET || "YOUR_WEBHOOK_SECRET_HERE",

    // Prefix trong nội dung chuyển khoản để parse UID
    DEPOSIT_PREFIX: "UID-",

    // TTL cho processed orders (24h)
    ORDER_TTL_SECONDS: 86400,
};

// ================================================================
// HELPERS
// ================================================================
function verifySignature(payload, signature) {
    const expected = crypto
        .createHmac("sha256", CONFIG.WEBHOOK_SECRET)
        .update(JSON.stringify(payload))
        .digest("hex");
    return crypto.timingSafeEqual(
        Buffer.from(signature, "hex"),
        Buffer.from(expected, "hex")
    );
}

function parseDepositNote(note) {
    // Format: BVD-SONG-{uid_short}-{order_fragment}
    if (!note || !note.startsWith(CONFIG.DEPOSIT_PREFIX)) return null;
    const parts = note.replace(CONFIG.DEPOSIT_PREFIX, "").split("-");
    if (parts.length < 1) return null;
    return { uidPrefix: parts[0], orderFragment: parts[1] || "" };
}

function generateProcessedDocId(txHash, amount, timestamp) {
    return crypto
        .createHash("sha256")
        .update(`${txHash}-${amount}-${timestamp}`)
        .digest("hex")
        .substring(0, 32);
}

// ================================================================
// WEBHOOK: VietQR / Payment Gateway Callback
// Endpoint: POST /vietqrWebhook
// ================================================================
exports.vietqrWebhook = functions.https.onRequest(async (req, res) => {
    // Only accept POST
    if (req.method !== "POST") {
        return res.status(405).json({ error: "Method not allowed" });
    }

    try {
        const payload = req.body;
        const signature = req.headers["x-webhook-signature"] || "";

        // 1. Verify signature (if webhook secret configured)
        if (CONFIG.WEBHOOK_SECRET !== "YOUR_WEBHOOK_SECRET_HERE") {
            if (!signature) {
                return res.status(401).json({ error: "Missing signature" });
            }
            try {
                if (!verifySignature(payload, signature)) {
                    return res.status(401).json({ error: "Invalid signature" });
                }
            } catch (e) {
                return res.status(401).json({ error: "Signature verification failed" });
            }
        }

        // 2. Extract transaction data
        // Adapt these fields to your actual payment gateway's webhook format
        const txHash = payload.transactionId || payload.txHash || payload.id;
        const amount = parseInt(payload.amount, 10);
        const note = (payload.description || payload.note || payload.content || "").trim();
        const timestamp = payload.timestamp || payload.createdAt || Date.now();
        const status = payload.status || "success";

        if (!txHash || !amount || amount <= 0) {
            return res.status(400).json({ error: "Invalid transaction data" });
        }

        if (status !== "success" && status !== "completed") {
            return res.status(200).json({ message: "Transaction not successful, skipped" });
        }

        // 3. Anti-replay: check if already processed
        const processedId = generateProcessedDocId(txHash, amount, timestamp);
        const processedRef = db.collection("processed_transactions").doc(processedId);

        // 4. Parse deposit note to find user
        const parsed = parseDepositNote(note);
        if (!parsed) {
            // Log unmatched transaction for manual review
            await db.collection("unmatched_deposits").add({
                txHash,
                amount,
                note,
                timestamp: new Date(timestamp),
                processedId,
                reason: "Could not parse deposit note",
                createdAt: admin.firestore.FieldValue.serverTimestamp(),
            });
            return res.status(200).json({ message: "Unmatched deposit, logged for review" });
        }

        // 5. Find user by UID prefix
        const usersSnapshot = await db
            .collection("users")
            .where("uidPrefix", "==", parsed.uidPrefix)
            .limit(1)
            .get();

        if (usersSnapshot.empty) {
            await db.collection("unmatched_deposits").add({
                txHash,
                amount,
                note,
                timestamp: new Date(timestamp),
                processedId,
                uidPrefix: parsed.uidPrefix,
                reason: "User not found",
                createdAt: admin.firestore.FieldValue.serverTimestamp(),
            });
            return res.status(200).json({ message: "User not found, logged for review" });
        }

        const userDoc = usersSnapshot.docs[0];
        const userId = userDoc.id;

        // 6. ATOMIC TRANSACTION: check duplicate + credit balance
        await db.runTransaction(async (tx) => {
            // Check if already processed (inside transaction for race condition safety)
            const processedDoc = await tx.get(processedRef);
            if (processedDoc.exists) {
                throw new functions.https.HttpsError(
                    "already-exists",
                    "Transaction already processed"
                );
            }

            // Get current user balance
            const userRef = db.collection("users").doc(userId);
            const userSnap = await tx.get(userRef);

            if (!userSnap.exists) {
                throw new functions.https.HttpsError("not-found", "User not found");
            }

            const currentBalance = userSnap.data().balance || 0;
            const newBalance = currentBalance + amount;

            // Credit balance atomically
            tx.update(userRef, {
                balance: newBalance,
                lastDeposit: admin.firestore.FieldValue.serverTimestamp(),
                totalDeposited: (userSnap.data().totalDeposited || 0) + amount,
            });

            // Mark transaction as processed with TTL
            tx.set(processedRef, {
                txHash,
                amount,
                userId,
                note,
                timestamp: new Date(timestamp),
                oldBalance: currentBalance,
                newBalance,
                processedAt: admin.firestore.FieldValue.serverTimestamp(),
                ttl: admin.firestore.Timestamp.fromMillis(
                    Date.now() + CONFIG.ORDER_TTL_SECONDS * 1000
                ),
            });

            // Log to deposit history
            const historyRef = db.collection("users").doc(userId).collection("deposits").doc();
            tx.set(historyRef, {
                txHash,
                amount,
                note,
                oldBalance: currentBalance,
                newBalance,
                timestamp: new Date(timestamp),
                createdAt: admin.firestore.FieldValue.serverTimestamp(),
            });
        });

        // 7. Send notification to admin (optional)
        await db.collection("notifications").add({
            type: "deposit_success",
            userId,
            amount,
            txHash,
            note,
            createdAt: admin.firestore.FieldValue.serverTimestamp(),
        });

        return res.status(200).json({
            success: true,
            message: `Credited ${amount} VND to user ${userId}`,
            processedId,
        });

    } catch (error) {
        if (error.message === "Transaction already processed") {
            return res.status(200).json({ message: "Duplicate webhook, already processed" });
        }
        console.error("Webhook error:", error);
        return res.status(500).json({ error: "Internal server error" });
    }
});

// ================================================================
// CALLABLE: Buy Key (atomic server-side validation)
// ================================================================
exports.buyKey = functions.https.onCallable(async (request, context) => {
    if (!context.auth) {
        throw new functions.https.HttpsError("unauthenticated", "Must be logged in");
    }

    const KEY_PRICE = 2000;
    const uid = context.auth.uid;

    const orderId = `SRV-${Date.now().toString(36).toUpperCase()}-${crypto.randomBytes(4).toString("hex").toUpperCase()}`;

    // Generate key code
    const segments = [];
    for (let i = 0; i < 4; i++) {
        segments.push(crypto.randomBytes(4).toString("hex").toUpperCase());
    }
    const keyCode = `KEY-${segments.join("-")}`;

    let result = {};

    await db.runTransaction(async (tx) => {
        const userRef = db.collection("users").doc(uid);
        const userSnap = await tx.get(userRef);

        if (!userSnap.exists) {
            throw new functions.https.HttpsError("not-found", "User not found");
        }

        const balance = userSnap.data().balance || 0;

        if (balance < KEY_PRICE) {
            throw new functions.https.HttpsError(
                "failed-precondition",
                `Insufficient balance: ${balance} < ${KEY_PRICE}`
            );
        }

        // Deduct balance
        tx.update(userRef, {
            balance: balance - KEY_PRICE,
            lastPurchase: admin.firestore.FieldValue.serverTimestamp(),
            totalSpent: (userSnap.data().totalSpent || 0) + KEY_PRICE,
            keysPurchased: (userSnap.data().keysPurchased || 0) + 1,
        });

        // Save purchased key
        const keyRef = db.collection("users").doc(uid).collection("keys").doc();
        tx.set(keyRef, {
            keyCode,
            orderId,
            price: KEY_PRICE,
            status: "active",
            createdAt: admin.firestore.FieldValue.serverTimestamp(),
        });

        // Log transaction
        const txRef = db.collection("users").doc(uid).collection("transactions").doc();
        tx.set(txRef, {
            type: "purchase",
            orderId,
            keyCode,
            amount: -KEY_PRICE,
            oldBalance: balance,
            newBalance: balance - KEY_PRICE,
            createdAt: admin.firestore.FieldValue.serverTimestamp(),
        });

        result = {
            keyCode,
            orderId,
            newBalance: balance - KEY_PRICE,
        };
    });

    return result;
});

// ================================================================
// SCHEDULED: Cleanup old processed transactions (daily)
// ================================================================
exports.cleanupOldTransactions = functions.scheduler.onSchedule(
    "every 24 hours",
    async () => {
        const cutoff = new Date(Date.now() - CONFIG.ORDER_TTL_SECONDS * 1000);
        const snapshot = await db
            .collection("processed_transactions")
            .where("ttl", "<", admin.firestore.Timestamp.fromDate(cutoff))
            .limit(500)
            .get();

        const batch = db.batch();
        snapshot.docs.forEach((doc) => batch.delete(doc.ref));

        if (!snapshot.empty) {
            await batch.commit();
        }

        console.log(`Cleaned up ${snapshot.size} old processed transactions`);
    }
);
